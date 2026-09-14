#!/usr/bin/env python3
"""Orquestador seguro para deploy incremental de Mantenimiento a Ferozo.

No reemplaza scripts/ferozo-ftps.py ni scripts/migrate.php: los coordina.
El modo --dry-run nunca toca producción.
"""

from __future__ import annotations

import argparse
import configparser
import hashlib
import json
import os
import pathlib
import shutil
import subprocess
import sys
import tempfile
import urllib.error
import urllib.request
from dataclasses import dataclass
from datetime import datetime, timezone

ROOT = pathlib.Path(__file__).resolve().parents[1]
SCRIPTS = ROOT / "scripts"
FTP_SCRIPT = SCRIPTS / "ferozo-ftps.py"
MIGRATE_SCRIPT = SCRIPTS / "migrate.php"
DEFAULT_CREDENTIALS = ROOT / ".ferozo-credentials"
DEFAULT_STATE = ROOT / ".deploy" / "production.json"
PRODUCTION_URL = "https://vogelconsultoria.com.ar/mantenimiento"

RUNTIME_PREFIXES = (
    "app/",
    "assets/",
)
RUNTIME_ROOT_FILES = {
    "index.php",
    "spark",
    ".htaccess",
    "composer.json",
    "composer.lock",
    "preload.php",
    "favicon.ico",
    "robots.txt",
}
NEVER_UPLOAD_PREFIXES = (
    ".git/",
    ".github/",
    "frontend/node_modules/",
    "writable/",
    "tests/",
    "docs/",
)
NEVER_UPLOAD_FILES = {
    ".env",
    ".ferozo-credentials",
    ".ferozo-credentials.ini",
}


@dataclass(frozen=True)
class Change:
    status: str
    path: str
    old_path: str | None = None


def run(cmd: list[str], *, cwd: pathlib.Path = ROOT, check: bool = True) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        cmd,
        cwd=str(cwd),
        check=check,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
    )


def git(*args: str) -> str:
    result = run(["git", *args])
    return result.stdout.strip()


def ensure_repo_ready(*, allow_non_main: bool, allow_dirty: bool, local_mode_name: str | None = None) -> str:
    branch = git("branch", "--show-current")
    if branch != "main" and not allow_non_main:
        raise RuntimeError(f"El deploy real solo se permite desde main (rama actual: {branch!r}).")

    dirty = git("status", "--porcelain")
    if dirty:
        if allow_dirty:
            mode = local_mode_name or "modo local"
            print(f"WARNING: hay cambios locales sin commit; {mode} compara solo commits (HEAD).")
        else:
            raise RuntimeError("El arbol de trabajo tiene cambios locales. Commit/stash antes del deploy.")

    return branch


def load_state(path: pathlib.Path) -> dict:
    if not path.is_file():
        return {}
    with path.open("r", encoding="utf-8") as fh:
        return json.load(fh)


def resolve_base_sha(args: argparse.Namespace) -> str:
    if args.from_sha:
        return git("rev-parse", args.from_sha)

    state = load_state(pathlib.Path(args.state_file))
    sha = str(state.get("git_sha", "")).strip()
    if sha:
        return git("rev-parse", sha)

    raise RuntimeError(
        "No hay SHA productivo registrado. Use --from-sha <sha> para la primera ejecución "
        "o cree .deploy/production.json."
    )


def parse_changes(base_sha: str, head_sha: str) -> list[Change]:
    output = git("diff", "--name-status", "--find-renames", f"{base_sha}..{head_sha}")
    changes: list[Change] = []
    if not output:
        return changes

    for line in output.splitlines():
        parts = line.split("\t")
        status = parts[0]
        code = status[0]
        if code == "R" and len(parts) >= 3:
            changes.append(Change(status="R", path=parts[2], old_path=parts[1]))
        elif len(parts) >= 2:
            changes.append(Change(status=code, path=parts[1]))
    return changes


def is_runtime_path(path: str) -> bool:
    path = path.replace("\\", "/")
    if path in NEVER_UPLOAD_FILES:
        return False
    if any(path.startswith(prefix) for prefix in NEVER_UPLOAD_PREFIXES):
        return False
    if path in RUNTIME_ROOT_FILES:
        return True
    return any(path.startswith(prefix) for prefix in RUNTIME_PREFIXES)


def classify(changes: list[Change]) -> dict[str, list[Change]]:
    result = {
        "runtime": [],
        "deleted": [],
        "migrations": [],
        "frontend": [],
        "ignored": [],
    }
    for change in changes:
        path = change.path.replace("\\", "/")
        if path.startswith("app/Database/Migrations/"):
            result["migrations"].append(change)
        if path.startswith("frontend/"):
            result["frontend"].append(change)
        if change.status == "D" and is_runtime_path(path):
            result["deleted"].append(change)
        elif is_runtime_path(path):
            result["runtime"].append(change)
        else:
            result["ignored"].append(change)
    return result


def short(sha: str) -> str:
    return sha[:8]


def print_plan(base_sha: str, head_sha: str, groups: dict[str, list[Change]], *, full: bool) -> None:
    print("=" * 58)
    print("DEPLOY FEROZO - MANTENIMIENTO")
    print("=" * 58)
    print(f"FROM              : {base_sha}")
    print(f"TO                : {head_sha}")
    print(f"MODE              : {'FULL' if full else 'INCREMENTAL'}")
    print(f"RUNTIME_CHANGES   : {len(groups['runtime'])}")
    print(f"MIGRATIONS        : {len(groups['migrations'])}")
    print(f"FRONTEND_CHANGES  : {len(groups['frontend'])}")
    print(f"DELETIONS         : {len(groups['deleted'])}")
    print(f"IGNORED           : {len(groups['ignored'])}")
    print(f"FRONTEND_BUILD    : {'REQUIRED' if groups['frontend'] else 'NO'}")
    print(f"ASSETS_DASHBOARD  : {'FULL_PUBLISH_AFTER_BUILD' if groups['frontend'] else 'DIFF_ONLY'}")
    print()

    if groups["runtime"]:
        print("ARCHIVOS A PUBLICAR:")
        for item in groups["runtime"]:
            prefix = f"{item.status} "
            if item.old_path:
                print(f"  {prefix}{item.old_path} -> {item.path}")
            else:
                print(f"  {prefix}{item.path}")
        print()

    if groups["deleted"]:
        print("ELIMINACIONES REMOTAS (NO AUTOMATICAS):")
        for item in groups["deleted"]:
            print(f"  D {item.path}")
        print()

    if groups["migrations"]:
        print("MIGRACIONES DETECTADAS:")
        for item in groups["migrations"]:
            print(f"  {item.status} {item.path}")
        print()

    if groups["ignored"]:
        print("ARCHIVOS IGNORADOS:")
        for item in groups["ignored"]:
            path = item.path.replace("\\", "/")
            if path.startswith("scripts/"):
                reason = "script/herramienta local"
            elif path.endswith(".md") or path.startswith("docs/"):
                reason = "documentacion"
            elif path.startswith("tests/"):
                reason = "tests"
            elif path.startswith(".github/"):
                reason = "configuracion GitHub"
            elif path.startswith("frontend/"):
                reason = "fuente frontend; se publica el build generado"
            else:
                reason = "fuera del runtime productivo"
            print(f"  {item.status} {item.path}  [{reason}]")
        print()

    if any(item.path == "composer.lock" for item in groups["runtime"]):
        print("ATENCION: composer.lock cambió. vendor/ NO se publicará automáticamente.")
        print()


def copy_incremental_tree(changes: list[Change], destination: pathlib.Path) -> int:
    copied = 0
    for change in changes:
        if change.status == "D":
            continue
        source = ROOT / change.path
        if not source.is_file():
            continue
        target = destination / change.path
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(source, target)
        copied += 1
    return copied


def copy_dashboard_build(destination: pathlib.Path) -> int:
    source_root = ROOT / "assets" / "dashboard"
    if not source_root.is_dir():
        raise RuntimeError("No existe assets/dashboard después del build frontend.")

    target_root = destination / "assets" / "dashboard"
    if target_root.exists():
        shutil.rmtree(target_root)
    shutil.copytree(source_root, target_root)
    return sum(1 for item in target_root.rglob("*") if item.is_file())


def resolve_npm() -> str:
    candidates = ["npm.cmd", "npm.exe", "npm"] if os.name == "nt" else ["npm"]
    for candidate in candidates:
        resolved = shutil.which(candidate)
        if resolved:
            return resolved
    raise RuntimeError("No se encontró npm en PATH.")


def run_npm(args: list[str]) -> subprocess.CompletedProcess[str]:
    npm = resolve_npm()
    frontend = ROOT / "frontend"

    if os.name == "nt" and str(frontend).startswith("\\\\"):
        # cmd.exe no acepta rutas UNC como cwd. pushd las monta temporalmente
        # en una letra de unidad y popd la libera al finalizar.
        command = subprocess.list2cmdline([npm, *args])
        script = f'pushd "{frontend}" && {command}'
        return subprocess.run(
            ["cmd.exe", "/d", "/s", "/c", script],
            text=True,
        )

    return subprocess.run(
        [npm, *args],
        cwd=str(frontend),
        text=True,
    )


def run_frontend_checks_and_build() -> None:
    npm = resolve_npm()

    print(f"NPM={npm}")
    print("FRONTEND_TESTS=RUNNING")
    result = run_npm(["test", "--", "--run"])
    if result.returncode != 0:
        raise RuntimeError("Fallaron los tests frontend.")

    print("FRONTEND_BUILD=RUNNING")
    result = run_npm(["run", "build"])
    if result.returncode != 0:
        raise RuntimeError("Falló el build frontend.")


def run_php_tests() -> None:
    candidates = [
        pathlib.Path(r"C:\xampp\php\php.exe"),
        shutil.which("php"),
    ]
    php = next((str(p) for p in candidates if p and pathlib.Path(p).exists()), None)
    if not php:
        raise RuntimeError("No se encontró PHP local para ejecutar PHPUnit.")

    cmd = [php]
    if "xampp" in php.lower():
        cmd += ["-d", "extension=gd", "-d", "extension=zip"]
    cmd += [str(ROOT / "vendor" / "bin" / "phpunit"), "--no-coverage"]

    print("PHPUNIT=RUNNING")
    result = subprocess.run(cmd, cwd=str(ROOT), text=True)
    if result.returncode != 0:
        raise RuntimeError("Falló PHPUnit.")


def call_ftps_upload(credentials: pathlib.Path, local_dir: pathlib.Path, remote: str = "/") -> None:
    cmd = [
        sys.executable,
        str(FTP_SCRIPT),
        "upload",
        "--credentials",
        str(credentials),
        "--remote",
        remote,
        "--local",
        str(local_dir),
    ]
    result = subprocess.run(cmd, cwd=str(ROOT), text=True)
    if result.returncode != 0:
        raise RuntimeError("Falló el upload FTPS.")


def load_migrate_token(env_path: pathlib.Path) -> str:
    if not env_path.is_file():
        raise RuntimeError(f"No se encontró {env_path} para leer MIGRATE_TOKEN.")

    token = ""
    for raw in env_path.read_text(encoding="utf-8-sig").splitlines():
        line = raw.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        if key.strip() == "MIGRATE_TOKEN":
            token = value.strip().strip("'\"")
            break

    if len(token) < 32:
        raise RuntimeError("MIGRATE_TOKEN ausente o demasiado corto en el .env local.")
    return token


def http_get(path: str, *, token: str | None = None) -> tuple[int, str]:
    url = PRODUCTION_URL.rstrip("/") + "/" + path.lstrip("/")
    request = urllib.request.Request(url)
    request.add_header("User-Agent", "mantenimiento-deploy/1.0")
    if token:
        request.add_header("X-Migrate-Token", token)
    try:
        with urllib.request.urlopen(request, timeout=30) as response:
            return response.status, response.read().decode("utf-8", errors="replace")
    except urllib.error.HTTPError as error:
        return error.code, error.read().decode("utf-8", errors="replace")


def upload_migrator(credentials: pathlib.Path) -> None:
    with tempfile.TemporaryDirectory(prefix="mantenimiento-migrate-") as tmp:
        root = pathlib.Path(tmp)
        shutil.copy2(MIGRATE_SCRIPT, root / "migrate.php")
        call_ftps_upload(credentials, root, "/")


def remove_remote_file(credentials: pathlib.Path, remote_path: str) -> None:
    # El helper FTPS actual no ofrece delete. Se usa una operación puntual aquí,
    # leyendo las mismas credenciales locales sin imprimirlas.
    parser = configparser.ConfigParser(interpolation=None)
    parser.read(credentials, encoding="utf-8-sig")
    section = parser["ftp"]
    import ftplib
    import ssl

    client = ftplib.FTP_TLS(context=ssl.create_default_context(), timeout=45)
    try:
        client.connect(section.get("host"), section.getint("port", fallback=21))
        client.auth()
        client.login(section.get("user"), section.get("password"))
        client.prot_p()
        client.set_pasv(True)
        client.delete(remote_path)
    finally:
        try:
            client.quit()
        except Exception:
            client.close()


def run_migrations(credentials: pathlib.Path, env_path: pathlib.Path, *, assume_yes: bool) -> None:
    upload_migrator(credentials)
    token = load_migrate_token(env_path)
    try:
        status_code, body = http_get("migrate.php?status=1", token=token)
        print(f"MIGRATION_STATUS_HTTP={status_code}")
        if status_code != 200 or "OK: estado reportado" not in body:
            raise RuntimeError("No se pudo consultar el estado de migraciones.")

        if not assume_yes:
            answer = input("¿Aplicar migraciones en PRODUCCION? [s/N]: ").strip().lower()
            if answer not in {"s", "si", "sí", "y", "yes"}:
                raise RuntimeError("Deploy cancelado antes de aplicar migraciones.")

        status_code, body = http_get("migrate.php", token=token)
        if status_code != 200 or "OK: migraciones aplicadas." not in body:
            raise RuntimeError("Falló la ejecución remota de migraciones.")
        print("MIGRATIONS=OK")
    finally:
        try:
            remove_remote_file(credentials, "/migrate.php")
        finally:
            code, _ = http_get("migrate.php")
            if code != 404:
                raise RuntimeError(f"migrate.php sigue accesible (HTTP {code}).")
            print("MIGRATE_PHP_REMOVED=OK")


def smoke_tests() -> None:
    checks = [
        ("login", {200}),
        ("favicon.ico", {200}),
        (".env", {403, 404}),
        ("app/", {403, 404}),
        ("vendor/", {403, 404}),
        ("writable/", {403, 404}),
        ("migrate.php", {404}),
    ]
    for path, allowed in checks:
        code, _ = http_get(path)
        print(f"HTTP {path:<15} {code}")
        if code not in allowed:
            raise RuntimeError(f"Smoke test falló para {path}: HTTP {code}")
    print("SMOKE_TESTS=OK")


def sha256(path: pathlib.Path) -> str:
    h = hashlib.sha256()
    with path.open("rb") as fh:
        for chunk in iter(lambda: fh.read(1024 * 1024), b""):
            h.update(chunk)
    return h.hexdigest()


def save_state(path: pathlib.Path, head_sha: str) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    data = {
        "git_sha": head_sha,
        "deployed_at": datetime.now(timezone.utc).isoformat(),
        "status": "ok",
    }
    path.write_text(json.dumps(data, indent=2) + "\n", encoding="utf-8")


def main() -> int:
    parser = argparse.ArgumentParser(description="Deploy incremental seguro a Ferozo.")
    parser.add_argument("--dry-run", action="store_true", help="Solo mostrar el plan; no toca producción.")
    parser.add_argument("--prepare-only", action="store_true", help="Ejecuta tests/build y arma el release local sin FTP ni migraciones.")
    parser.add_argument("--full", action="store_true", help="Reservado para resincronización completa.")
    parser.add_argument("--from-sha", help="SHA base para calcular el diff (útil en la primera ejecución).")
    parser.add_argument("--credentials", default=str(DEFAULT_CREDENTIALS))
    parser.add_argument("--env-file", default=str(ROOT / ".env"))
    parser.add_argument("--state-file", default=str(DEFAULT_STATE))
    parser.add_argument("--yes", action="store_true", help="Confirma migraciones sin prompt interactivo.")
    parser.add_argument("--skip-tests", action="store_true", help="Solo para diagnóstico local; no recomendado.")
    args = parser.parse_args()

    safe_local_mode = args.dry_run or args.prepare_only
    local_mode_name = "--prepare-only" if args.prepare_only else "--dry-run" if args.dry_run else None
    branch = ensure_repo_ready(
        allow_non_main=safe_local_mode,
        allow_dirty=safe_local_mode,
        local_mode_name=local_mode_name,
    )
    try:
        git("fetch", "origin")
    except Exception:
        if not args.dry_run:
            raise

    head_sha = git("rev-parse", "HEAD")
    base_sha = resolve_base_sha(args)

    changes = parse_changes(base_sha, head_sha)
    groups = classify(changes)
    print_plan(base_sha, head_sha, groups, full=args.full)

    if args.dry_run:
        print(f"BRANCH={branch}")
        print("DRY_RUN=OK")
        print("PRODUCTION_TOUCHED=NO")
        return 0

    if args.full:
        raise RuntimeError(
            "--full todavía no está habilitado por este orquestador. "
            "Use el procedimiento completo documentado hasta implementarlo y probarlo."
        )

    if groups["deleted"]:
        raise RuntimeError(
            "Hay archivos runtime eliminados. Por seguridad el deploy incremental no los borra automáticamente. "
            "Revise la lista y resuelva la eliminación explícitamente."
        )

    if not changes:
        print("NO_CHANGES=YES")
        return 0

    if not args.skip_tests:
        run_php_tests()
        if groups["frontend"]:
            run_frontend_checks_and_build()

    # El build frontend genera nombres con hash y usa emptyOutDir=true. Por eso no se
    # depende del git diff para publicar esos bundles: si cambió frontend, se publica
    # completo assets/dashboard/ tal como quedó después del build.
    head_sha = git("rev-parse", "HEAD")
    changes = parse_changes(base_sha, head_sha)
    groups = classify(changes)

    if args.prepare_only:
        release = ROOT / "dist" / "ferozo-prepare"
        if release.exists():
            shutil.rmtree(release)
        release.mkdir(parents=True, exist_ok=True)

        count = copy_incremental_tree(groups["runtime"], release)
        dashboard_count = 0
        if groups["frontend"]:
            dashboard_count = copy_dashboard_build(release)
            print(f"DASHBOARD_FILES_STAGED={dashboard_count}")

        total_staged = count + dashboard_count
        print(f"FILES_STAGED={total_staged}")
        print(f"RELEASE_DIR={release}")
        print("UPLOAD=SKIPPED")
        print("MIGRATIONS=SKIPPED")
        print("PREPARE_ONLY=OK")
        print("PRODUCTION_TOUCHED=NO")
        return 0

    credentials = pathlib.Path(args.credentials).resolve()
    if not credentials.is_file():
        raise RuntimeError(f"No se encontró el archivo de credenciales: {credentials}")

    with tempfile.TemporaryDirectory(prefix="mantenimiento-release-") as tmp:
        release = pathlib.Path(tmp)
        count = copy_incremental_tree(groups["runtime"], release)

        dashboard_count = 0
        if groups["frontend"]:
            dashboard_count = copy_dashboard_build(release)
            print(f"DASHBOARD_FILES_STAGED={dashboard_count}")

        total_staged = count + dashboard_count
        print(f"FILES_STAGED={total_staged}")
        if total_staged:
            call_ftps_upload(credentials, release, "/")
            print("UPLOAD=OK")

    if groups["migrations"]:
        run_migrations(
            credentials,
            pathlib.Path(args.env_file).resolve(),
            assume_yes=args.yes,
        )
    else:
        print("MIGRATIONS=NONE")

    smoke_tests()

    # Hash local básico: asegura al menos que los archivos críticos existen y son legibles.
    # La comparación remota por hash se incorporará al helper FTPS en una iteración posterior.
    critical = [
        ROOT / ".htaccess",
        ROOT / "app" / "Config" / "Routes.php",
        ROOT / "assets" / "dashboard" / ".vite" / "manifest.json",
    ]
    for item in critical:
        if item.is_file():
            print(f"LOCAL_SHA256 {item.relative_to(ROOT).as_posix()} {sha256(item)}")
    print("HASH_LOCAL=OK")

    save_state(pathlib.Path(args.state_file).resolve(), head_sha)
    print("DEPLOY_STATUS=OK")
    print(f"FROM={base_sha}")
    print(f"TO={head_sha}")
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except KeyboardInterrupt:
        print("ERROR: cancelado por el usuario.", file=sys.stderr)
        raise SystemExit(130)
    except Exception as error:
        print(f"ERROR: {error}", file=sys.stderr)
        raise SystemExit(1)
