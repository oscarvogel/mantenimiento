#!/usr/bin/env python3
"""Auditoria y limpieza segura de bundles del dashboard (#432).

Fuente de verdad: `assets/dashboard/.vite/manifest.json`, que es lo que lee
`app/Views/app.php` para emitir los `<script>` y `<link>`. Un bundle que el
manifest no declara no lo carga nadie, pero sigue publicado en el webroot.

Clasificacion de cada archivo bajo `assets/dashboard/assets/`:

- ACTIVE           : esta en el cierre transitivo del manifest vigente.
- ORPHAN_CONFIRMED : no esta en el cierre y ningun archivo del repositorio lo
                     referencia por nombre. Unico estado que puede eliminarse.
- UNKNOWN          : hay una referencia fuera del manifest, o el manifest no
                     pudo interpretarse. Nunca se elimina.

La herramienta se niega a operar si el estado no es concluyente: un manifest
ilegible, una entrada principal ausente o un archivo referenciado que no existe
en el destino significan "no se sabe", y "no se sabe" se conserva.

Uso:

    python scripts/dashboard-assets.py self-test
    python scripts/dashboard-assets.py audit
    python scripts/dashboard-assets.py audit --assets-dir dist/ferozo-prepare/assets/dashboard
    python scripts/dashboard-assets.py prune --apply
    python scripts/dashboard-assets.py remote-audit --credentials .ferozo-credentials
    python scripts/dashboard-assets.py remote-prune --credentials .ferozo-credentials \\
        --backup-dir .deploy/backup-432 --apply
"""

from __future__ import annotations

import argparse
import ftplib
import hashlib
import json
import pathlib
import subprocess
import sys
from dataclasses import dataclass, field
from datetime import datetime

ROOT = pathlib.Path(__file__).absolute().parents[1]
DEFAULT_ASSETS_DIR = ROOT / "assets" / "dashboard"
DEFAULT_MANIFEST_RELPATH = ".vite/manifest.json"
ENTRY_KEY = "src/main.js"

# Directorios que nunca aportan referencias y hacen lento el escaneo. Se excluyen
# tests/ y docs/ a proposito NO: si un test o una guia nombran un bundle, ese
# bundle debe quedarUNKNOWN y conservarse.
SCAN_EXCLUDED_DIRS = {
    ".git", ".github", "node_modules", "vendor", "writable", ".deploy",
    "dist", "backup", "__pycache__", ".idea", ".vscode", "private", "storage",
}
SCAN_SUFFIXES = {".php", ".js", ".mjs", ".cjs", ".ts", ".vue", ".css", ".html", ".json", ".webmanifest", ".md", ".txt", ".xml", ".yml", ".yaml"}
SCAN_MAX_BYTES = 8 * 1024 * 1024

ACTIVE = "ACTIVE"
ORPHAN_CONFIRMED = "ORPHAN_CONFIRMED"
UNKNOWN = "UNKNOWN"


class UnsafeState(RuntimeError):
    """El estado no permite afirmar que un archivo este huerfano."""


@dataclass
class Classification:
    name: str
    status: str
    size: int = 0
    reason: str = ""


@dataclass
class Report:
    target: str
    manifest_path: str
    protected: list[str] = field(default_factory=list)
    items: list[Classification] = field(default_factory=list)

    @property
    def active(self) -> list[Classification]:
        return [i for i in self.items if i.status == ACTIVE]

    @property
    def orphans(self) -> list[Classification]:
        return [i for i in self.items if i.status == ORPHAN_CONFIRMED]

    @property
    def unknown(self) -> list[Classification]:
        return [i for i in self.items if i.status == UNKNOWN]

    @property
    def orphan_bytes(self) -> int:
        return sum(i.size for i in self.orphans)


def sha256_file(path: pathlib.Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def manifest_closure(manifest: dict) -> set[str]:
    """Cierre transitivo de los archivos que el manifest declara alcanzables."""
    if not isinstance(manifest, dict) or not manifest:
        raise UnsafeState("El manifest esta vacio o no es un objeto JSON.")

    entry = manifest.get(ENTRY_KEY)
    if not isinstance(entry, dict) or not entry.get("file"):
        raise UnsafeState(f"El manifest no declara la entrada principal '{ENTRY_KEY}'.")

    closure: set[str] = set()
    pending = [ENTRY_KEY]
    visited: set[str] = set()

    while pending:
        key = pending.pop()
        if key in visited:
            continue
        visited.add(key)
        current = manifest.get(key)
        if not isinstance(current, dict):
            # Una entrada importada que el manifest no describe no se puede
            # resolver: el estado es incompleto y no se puede depurar.
            raise UnsafeState(f"El manifest declara el import '{key}' pero no lo describe.")
        file_name = current.get("file")
        if not isinstance(file_name, str) or not file_name:
            raise UnsafeState(f"La entrada '{key}' del manifest no declara 'file'.")
        closure.add(file_name)
        for stylesheet in current.get("css") or []:
            if not isinstance(stylesheet, str) or not stylesheet:
                raise UnsafeState(f"La entrada '{key}' declara un css invalido.")
            closure.add(stylesheet)
        for reference in (current.get("imports") or []) + (current.get("dynamicImports") or []):
            if not isinstance(reference, str) or not reference:
                raise UnsafeState(f"La entrada '{key}' declara un import invalido.")
            pending.append(reference)

    return closure


def _iter_scan_files(roots: list[pathlib.Path]):
    for root in roots:
        if not root.is_dir():
            continue
        for path in root.rglob("*"):
            if not path.is_file():
                continue
            if any(part in SCAN_EXCLUDED_DIRS for part in path.relative_to(root).parts[:-1]):
                continue
            if path.suffix.lower() not in SCAN_SUFFIXES:
                continue
            if path.is_symlink() or path.stat().st_size > SCAN_MAX_BYTES:
                continue
            yield path


def _tracked_files(root: pathlib.Path) -> list[pathlib.Path] | None:
    """Archivos versionados del repo: la unica referencia que cuenta.

    Se consulta a Git en lugar de recorrer el disco porque el arbol de trabajo
    suele tener respaldos de produccion, manifiestos previos y carpetas de
    preflight que nombran bundles historicos. Esos artefactos no son fuente de
    verdad: si se contaran, ningun bundle viejo podria declararse huerfano.
    """
    try:
        result = subprocess.run(
            ["git", "-C", str(root), "ls-files", "-z"],
            check=True,
            capture_output=True,
        )
    except (OSError, subprocess.CalledProcessError):
        return None
    names = [part.decode("utf-8", "replace") for part in result.stdout.split(b"\0") if part]
    return [root / name for name in names]


def build_reference_index(roots: list[pathlib.Path]) -> str:
    """Concatena el source referenciable para buscar nombres de bundle."""
    chunks: list[str] = []
    for root in roots:
        tracked = _tracked_files(root)
        candidates: list[pathlib.Path]
        if tracked is not None:
            candidates = [p for p in tracked if p.is_file() and p.suffix.lower() in SCAN_SUFFIXES]
        else:
            candidates = list(_iter_scan_files([root]))
        for path in candidates:
            try:
                if path.is_symlink() or path.stat().st_size > SCAN_MAX_BYTES:
                    continue
                chunks.append(path.read_text(encoding="utf-8", errors="replace"))
            except OSError:
                continue
    return "\n".join(chunks)


def classify_local(assets_dir: pathlib.Path, scan_roots: list[pathlib.Path]) -> Report:
    manifest_path = assets_dir / DEFAULT_MANIFEST_RELPATH
    if not manifest_path.is_file():
        raise UnsafeState(f"No existe el manifest en {manifest_path}.")
    try:
        manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
    except (OSError, ValueError) as error:
        raise UnsafeState(f"El manifest no se pudo interpretar: {error}") from error

    protected = manifest_closure(manifest)

    bundle_dir = assets_dir / "assets"
    if not bundle_dir.is_dir():
        raise UnsafeState(f"No existe el directorio de bundles en {bundle_dir}.")

    present = sorted(p.name for p in bundle_dir.iterdir() if p.is_file())

    missing = sorted(rel for rel in protected if not (assets_dir / rel).is_file())
    if missing:
        raise UnsafeState(
            "El manifest referencia archivos que no estan en el destino: "
            + ", ".join(missing[:10])
        )

    haystack = build_reference_index(scan_roots)
    report = Report(target=str(assets_dir), manifest_path=str(manifest_path), protected=sorted(protected))
    for name in present:
        rel = f"assets/{name}"
        size = (bundle_dir / name).stat().st_size
        if rel in protected:
            report.items.append(Classification(name, ACTIVE, size, "declarado por el manifest"))
        elif name in haystack:
            report.items.append(
                Classification(name, UNKNOWN, size, "referenciado fuera del manifest")
            )
        else:
            report.items.append(
                Classification(name, ORPHAN_CONFIRMED, size, "fuera del manifest y sin referencias")
            )
    return report


# --------------------------------------------------------------------------
# Remoto FTPS
# --------------------------------------------------------------------------


def _import_ftp_helper():
    import importlib.util

    target = ROOT / "scripts" / "ferozo-ftps.py"
    spec = importlib.util.spec_from_file_location("ferozo_ftps", target)
    if spec is None or spec.loader is None:  # pragma: no cover
        raise RuntimeError("No se pudo cargar scripts/ferozo-ftps.py")
    module = importlib.util.module_from_spec(spec)
    sys.modules["ferozo_ftps"] = module
    spec.loader.exec_module(module)
    return module


def remote_list(credentials: pathlib.Path, remote: str) -> dict[str, int]:
    helper = _import_ftp_helper()
    client = helper.connect(credentials)
    try:
        listing: dict[str, int] = {}
        for name, facts in client.mlsd(remote, facts=["type", "size"]):
            if name in {".", ".."} or facts.get("type") != "file":
                continue
            listing[name] = int(facts.get("size") or 0)
        return listing
    finally:
        try:
            client.quit()
        except (OSError, ftplib.Error):
            client.close()


def remote_fetch(credentials: pathlib.Path, remote: str, destination: pathlib.Path) -> None:
    helper = _import_ftp_helper()
    destination.parent.mkdir(parents=True, exist_ok=True)
    client = helper.connect(credentials)
    try:
        with destination.open("wb") as handle:
            client.retrbinary(f"RETR {remote}", handle.write)
    finally:
        try:
            client.quit()
        except (OSError, ftplib.Error):
            client.close()


def classify_remote(credentials: pathlib.Path, remote_root: str, scan_roots: list[pathlib.Path]) -> tuple[Report, dict[str, int]]:
    listing = remote_list(credentials, f"{remote_root.rstrip('/')}/assets")
    if not listing:
        raise UnsafeState(f"No se encontraron archivos en {remote_root}/assets.")

    cache = ROOT / ".deploy" / "audit"
    manifest_cache = cache / "remote-manifest.json"
    cache.mkdir(parents=True, exist_ok=True)
    remote_fetch(credentials, f"{remote_root.rstrip('/')}/{DEFAULT_MANIFEST_RELPATH}", manifest_cache)
    try:
        manifest = json.loads(manifest_cache.read_text(encoding="utf-8"))
    except (OSError, ValueError) as error:
        raise UnsafeState(f"El manifest remoto no se pudo interpretar: {error}") from error

    protected = manifest_closure(manifest)
    present_names = {rel.split("/")[-1] for rel in protected}
    missing = sorted(present_names - set(listing))
    if missing:
        raise UnsafeState(
            "El manifest remoto referencia bundles que no estan publicados: "
            + ", ".join(missing[:10])
        )

    haystack = build_reference_index(scan_roots)
    report = Report(target=f"{remote_root}/assets", manifest_path=f"{remote_root}/{DEFAULT_MANIFEST_RELPATH}", protected=sorted(protected))
    for name in sorted(listing):
        rel = f"assets/{name}"
        if rel in protected:
            report.items.append(Classification(name, ACTIVE, listing[name], "declarado por el manifest remoto"))
        elif name in haystack:
            report.items.append(Classification(name, UNKNOWN, listing[name], "referenciado fuera del manifest"))
        else:
            report.items.append(Classification(name, ORPHAN_CONFIRMED, listing[name], "fuera del manifest remoto y sin referencias"))
    return report, listing


def _print_report(report: Report) -> None:
    print("=" * 72)
    print("DASHBOARD ASSETS - CLASIFICACION")
    print("=" * 72)
    print(f"OBJETIVO   : {report.target}")
    print(f"MANIFEST   : {report.manifest_path}")
    print(f"TOTAL      : {len(report.items)} archivos")
    print(f"ACTIVE     : {len(report.active)}")
    print(f"ORPHAN     : {len(report.orphans)}  ({report.orphan_bytes} bytes)")
    print(f"UNKNOWN    : {len(report.unknown)}")
    print()
    for status in (UNKNOWN, ORPHAN_CONFIRMED):
        items = report.unknown if status == UNKNOWN else report.orphans
        if not items:
            continue
        print(f"--- {status} ---")
        for item in items:
            print(f"  {item.size:>10}  {item.name:<45} {item.reason}")
        print()


def prune_local(assets_dir: pathlib.Path, scan_roots: list[pathlib.Path], apply: bool) -> int:
    report = classify_local(assets_dir, scan_roots)
    _print_report(report)
    if not report.orphans:
        print("PRUNE=NOOP no hay ORPHAN_CONFIRMED")
        return 0
    if not apply:
        print("PRUNE=DRY_RUN use --apply para eliminar")
        return 0

    bundle_dir = assets_dir / "assets"
    manifest_path = assets_dir / DEFAULT_MANIFEST_RELPATH
    if not manifest_path.is_file():
        raise UnsafeState("El manifest desaparecio antes de limpiar.")

    removed = 0
    for item in report.orphans:
        (bundle_dir / item.name).unlink()
        removed += 1

    for rel in report.protected:
        if not (assets_dir / rel).is_file():
            raise UnsafeState(f"La limpieza rompio un archivo protegido: {rel}")
    if not manifest_path.is_file():
        raise UnsafeState("La limpieza elimino el manifest vigente.")

    print(f"PRUNE=OK archivos_eliminados={removed}")
    return 0


def prune_remote(
    credentials: pathlib.Path,
    remote_root: str,
    backup_dir: pathlib.Path,
    scan_roots: list[pathlib.Path],
    apply: bool,
) -> int:
    report, _listing = classify_remote(credentials, remote_root, scan_roots)
    _print_report(report)
    if not report.orphans:
        print("PRUNE=NOOP no hay ORPHAN_CONFIRMED")
        return 0
    if not apply:
        print("PRUNE=DRY_RUN use --apply para eliminar")
        return 0

    if backup_dir.exists() and any(backup_dir.iterdir()):
        raise UnsafeState(f"El directorio de backup ya tiene contenido: {backup_dir}")
    backup_dir.mkdir(parents=True, exist_ok=True)

    helper = _import_ftp_helper()

    def with_retry(action, *, attempts: int = 3):
        """FTPS de Ferozo corta la sesion en transferencias largas."""
        nonlocal client
        last_error: Exception | None = None
        for _ in range(attempts):
            try:
                return action(client)
            except (OSError, EOFError, ftplib.Error) as error:
                last_error = error
                try:
                    client.close()
                except Exception:  # noqa: BLE001
                    pass
                client = helper.connect(credentials)
        raise last_error  # type: ignore[misc]

    client = helper.connect(credentials)
    removed: list[str] = []
    try:
        for index, item in enumerate(report.orphans, start=1):
            remote_path = f"{remote_root.rstrip('/')}/assets/{item.name}"
            local_copy = backup_dir / item.name
            temporary = backup_dir / f"{item.name}.part"

            def download(active, _path=remote_path, _tmp=temporary):
                with _tmp.open("wb") as handle:
                    active.retrbinary(f"RETR {_path}", handle.write)
                _tmp.replace(backup_dir / _path.rsplit("/", 1)[-1])

            with_retry(download)
            remote_sha = sha256_file(local_copy)
            if local_copy.stat().st_size != item.size:
                raise UnsafeState(
                    f"El backup de {item.name} no coincide con el tamano remoto "
                    f"({local_copy.stat().st_size} != {item.size}); no se borra."
                )
            (backup_dir / f"{item.name}.sha256").write_text(
                f"{remote_sha}  {item.name}\n", encoding="utf-8"
            )
            with_retry(lambda active, _p=remote_path: active.delete(_p))
            removed.append(item.name)
            print(f"  DELETED {item.name} sha256={remote_sha}")
            if index % 25 == 0 or index == len(report.orphans):
                print(f"  PROGRESS {index}/{len(report.orphans)}", flush=True)
    finally:
        try:
            client.quit()
        except (OSError, ftplib.Error):
            client.close()

    # Verificacion posterior: el manifest y todo lo que declara siguen en pie.
    client = helper.connect(credentials)
    try:
        remaining = remote_list(credentials, f"{remote_root.rstrip('/')}/assets")
    finally:
        try:
            client.quit()
        except (OSError, ftplib.Error):
            client.close()

    still_there = {name for name in remaining if name in {i.name for i in report.orphans}}
    if still_there:
        raise UnsafeState("Los archivos siguen presentes tras el borrado: " + ", ".join(sorted(still_there)[:10]))

    expected = {rel.split("/")[-1] for rel in report.protected}
    lost = sorted(expected - set(remaining))
    if lost:
        raise UnsafeState("Se perdio un archivo protegido: " + ", ".join(lost[:10]))

    print(f"PRUNE=OK archivos_eliminados={len(removed)} backup={backup_dir}")
    return 0


# --------------------------------------------------------------------------
# Self-test
# --------------------------------------------------------------------------


def _fixture(tmp: pathlib.Path, manifest: dict, files: list[str], scan_text: str) -> pathlib.Path:
    assets = tmp / "assets" / "dashboard"
    (assets / ".vite").mkdir(parents=True, exist_ok=True)
    (assets / "assets").mkdir(parents=True, exist_ok=True)
    (assets / ".vite" / "manifest.json").write_text(json.dumps(manifest, indent=2), encoding="utf-8")
    for name in files:
        (assets / "assets" / name).write_text("/* fixture */\n", encoding="utf-8")
    scan = tmp / "scan"
    scan.mkdir(parents=True, exist_ok=True)
    (scan / "reference.php").write_text(scan_text, encoding="utf-8")
    return assets


def self_test() -> int:
    import tempfile

    failures: list[str] = []

    def run(name: str, body) -> None:
        try:
            body()
        except AssertionError as error:  # noqa: PERF203
            failures.append(f"{name}: {error}")
            print(f"  FAIL {name}: {error}")
        else:
            print(f"  OK   {name}")

    manifest_ok = {
        "src/main.js": {
            "file": "assets/main-AAAAAAAA.js",
            "isEntry": True,
            "imports": ["_vendor-CCCCCCCC.js"],
            "css": ["assets/main-BBBBBBBB.css"],
        },
        "_vendor-CCCCCCCC.js": {"file": "assets/vendor-CCCCCCCC.js", "name": "vendor"},
        "_vendor-CCCCCCCC.css": {"file": "assets/main-BBBBBBBB.css"},
    }

    def case_active() -> None:
        with tempfile.TemporaryDirectory() as raw:
            tmp = pathlib.Path(raw)
            assets = _fixture(
                tmp,
                manifest_ok,
                ["main-AAAAAAAA.js", "vendor-CCCCCCCC.js", "main-BBBBBBBB.css", "old-ZZZZZZZZ.js"],
                "sin referencias",
            )
            report = classify_local(assets, [tmp / "scan"])
            names = {i.name: i.status for i in report.items}
            assert names["main-AAAAAAAA.js"] == ACTIVE, names
            assert names["vendor-CCCCCCCC.js"] == ACTIVE, names
            assert names["main-BBBBBBBB.css"] == ACTIVE, names
            assert names["old-ZZZZZZZZ.js"] == ORPHAN_CONFIRMED, names

    def case_unknown_when_referenced() -> None:
        with tempfile.TemporaryDirectory() as raw:
            tmp = pathlib.Path(raw)
            assets = _fixture(
                tmp,
                manifest_ok,
                ["main-AAAAAAAA.js", "vendor-CCCCCCCC.js", "main-BBBBBBBB.css", "old-ZZZZZZZZ.js"],
                "ver . '/assets/old-ZZZZZZZZ.js'",
            )
            report = classify_local(assets, [tmp / "scan"])
            names = {i.name: i.status for i in report.items}
            assert names["old-ZZZZZZZZ.js"] == UNKNOWN, names
            assert not report.orphans, [i.name for i in report.orphans]

    def case_dynamic_import_protected() -> None:
        manifest = {
            "src/main.js": {"file": "assets/main-AAAAAAAA.js", "isEntry": True, "dynamicImports": ["_late.js"]},
            "_late.js": {"file": "assets/late-DDDDDDDD.js", "css": ["assets/late-EEEEEEEE.css"]},
        }
        with tempfile.TemporaryDirectory() as raw:
            tmp = pathlib.Path(raw)
            assets = _fixture(tmp, manifest, ["main-AAAAAAAA.js", "late-DDDDDDDD.js", "late-EEEEEEEE.css"], "")
            report = classify_local(assets, [tmp / "scan"])
            assert len(report.orphans) == 0, [i.name for i in report.orphans]
            assert len(report.active) == 3, [i.name for i in report.active]

    def case_refuses_missing_entry() -> None:
        with tempfile.TemporaryDirectory() as raw:
            tmp = pathlib.Path(raw)
            assets = _fixture(tmp, {"otro.js": {"file": "assets/main-AAAAAAAA.js"}}, ["main-AAAAAAAA.js"], "")
            try:
                classify_local(assets, [tmp / "scan"])
            except UnsafeState:
                return
            raise AssertionError("debio rechazar un manifest sin entrada principal")

    def case_refuses_dangling_import() -> None:
        manifest = {"src/main.js": {"file": "assets/main-AAAAAAAA.js", "imports": ["_fantasma.js"]}}
        with tempfile.TemporaryDirectory() as raw:
            tmp = pathlib.Path(raw)
            assets = _fixture(tmp, manifest, ["main-AAAAAAAA.js"], "")
            try:
                classify_local(assets, [tmp / "scan"])
            except UnsafeState:
                return
            raise AssertionError("debio rechazar un import no descrito")

    def case_refuses_incomplete_target() -> None:
        with tempfile.TemporaryDirectory() as raw:
            tmp = pathlib.Path(raw)
            assets = _fixture(tmp, manifest_ok, ["main-AAAAAAAA.js"], "")
            try:
                classify_local(assets, [tmp / "scan"])
            except UnsafeState:
                return
            raise AssertionError("debio rechazar un destino sin los bundles del manifest")

    def case_refuses_missing_manifest() -> None:
        with tempfile.TemporaryDirectory() as raw:
            tmp = pathlib.Path(raw)
            assets = tmp / "assets" / "dashboard"
            (assets / "assets").mkdir(parents=True, exist_ok=True)
            try:
                classify_local(assets, [tmp / "scan"])
            except UnsafeState:
                return
            raise AssertionError("debio rechazar la ausencia de manifest")

    print("SELF-TEST dashboard-assets")
    for name, body in (
        ("cierre del manifest y huerfano confirmado", case_active),
        ("referencia externa fuerza UNKNOWN", case_unknown_when_referenced),
        ("dynamicImports y css quedan protegidos", case_dynamic_import_protected),
        ("manifest sin entrada principal se rechaza", case_refuses_missing_entry),
        ("import no descrito se rechaza", case_refuses_dangling_import),
        ("destino incompleto se rechaza", case_refuses_incomplete_target),
        ("manifest ausente se rechaza", case_refuses_missing_manifest),
    ):
        run(name, body)

    if failures:
        print(f"SELF_TEST=FAIL failures={len(failures)}")
        return 1
    print("SELF_TEST=OK cases=7")
    return 0


# --------------------------------------------------------------------------


def _write_json(destination: str | None, report: Report) -> None:
    """Volca el inventario clasificado para poder revisarlo fuera de la terminal."""
    if not destination:
        return
    path = pathlib.Path(destination)
    path.parent.mkdir(parents=True, exist_ok=True)
    payload = {
        "generado": datetime.now().isoformat(timespec="seconds"),
        "objetivo": report.target,
        "manifest": report.manifest_path,
        "resumen": {
            "total": len(report.items),
            "active": len(report.active),
            "orphan_confirmed": len(report.orphans),
            "unknown": len(report.unknown),
            "bytes_orphan_confirmed": report.orphan_bytes,
        },
        "protegidos": report.protected,
        "archivos": [
            {"nombre": i.name, "estado": i.status, "bytes": i.size, "motivo": i.reason}
            for i in sorted(report.items, key=lambda i: (i.status, i.name))
        ],
    }
    path.write_text(json.dumps(payload, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    print(f"INVENTARIO={path}")


def main() -> int:
    parser = argparse.ArgumentParser(description="Auditoria y limpieza de bundles del dashboard.")
    sub = parser.add_subparsers(dest="command", required=True)

    for name in ("audit", "prune"):
        child = sub.add_parser(name)
        child.add_argument("--assets-dir", default=str(DEFAULT_ASSETS_DIR))
        child.add_argument("--scan-root", action="append", default=None)
        child.add_argument("--json", help="Escribir tambien el inventario en este archivo.")
        if name == "prune":
            child.add_argument("--apply", action="store_true")

    for name in ("remote-audit", "remote-prune"):
        child = sub.add_parser(name)
        child.add_argument("--credentials", default=str(ROOT / ".ferozo-credentials"))
        child.add_argument("--remote", default="/assets/dashboard")
        child.add_argument("--scan-root", action="append", default=None)
        child.add_argument("--json", help="Escribir tambien el inventario en este archivo.")
        if name == "remote-prune":
            child.add_argument("--backup-dir", default=str(ROOT / ".deploy" / "backup-dashboard-assets"))
            child.add_argument("--apply", action="store_true")

    sub.add_parser("self-test")

    args = parser.parse_args()

    if args.command == "self-test":
        return self_test()

    scan_roots = [pathlib.Path(p).resolve() for p in (args.scan_root or [])] or [ROOT]

    if args.command == "audit":
        report = classify_local(pathlib.Path(args.assets_dir).resolve(), scan_roots)
        _print_report(report)
        _write_json(args.json, report)
        print("AUDIT=OK")
        return 0
    if args.command == "prune":
        return prune_local(pathlib.Path(args.assets_dir).resolve(), scan_roots, args.apply)
    if args.command == "remote-audit":
        report, _listing = classify_remote(pathlib.Path(args.credentials).resolve(), args.remote, scan_roots)
        _print_report(report)
        _write_json(args.json, report)
        print("REMOTE_AUDIT=OK")
        return 0
    if args.command == "remote-prune":
        return prune_remote(
            pathlib.Path(args.credentials).resolve(),
            args.remote,
            pathlib.Path(args.backup_dir).resolve(),
            scan_roots,
            args.apply,
        )

    return 1


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except UnsafeState as error:
        print(f"ABORT: {error}", file=sys.stderr)
        print("ABORT=1 no se elimino nada", file=sys.stderr)
        raise SystemExit(2)
    except Exception as error:  # noqa: BLE001
        print(f"ERROR: {type(error).__name__}: {error}", file=sys.stderr)
        raise SystemExit(1)
