#!/usr/bin/env python3
"""Deploy quirurgico del frontend a produccion (Ferozo, FTPS).

Sube SOLO assets/dashboard (bundles + manifest). No toca PHP, vendor ni migraciones.

Pasos:
  1. Verifica que estas en main, sin cambios locales y al dia con origin/main.
  2. Verifica que los bundles versionados coincidan con el source (si hay npm).
  3. Baja por FTPS el manifest que sirve produccion HOY (no usa copias locales viejas).
  4. Muestra el plan y pide confirmacion (salvo --yes).
  5. Respalda en .deploy/assets-<fecha>/ el manifest actual de produccion.
  6. Sube los bundles nuevos que produccion no tiene.
  7. Sube el manifest al final (nunca apunta a un bundle que todavia no esta).
  8. Verifica por HTTP que cada bundle del manifest nuevo responda 200.
  9. Solo si todo da OK: respalda y borra los bundles huerfanos del manifest viejo.

Uso (desde la raiz del repo, en Windows):
  py -3 scripts\\deploy-assets-quirurgico.py --dry-run   # solo muestra el plan
  py -3 scripts\\deploy-assets-quirurgico.py             # despliega (pide confirmacion)
  py -3 scripts\\deploy-assets-quirurgico.py --yes       # despliega sin preguntar

Rollback: subir el manifest respaldado en .deploy/assets-<fecha>/manifest.json a
/assets/dashboard/.vite/manifest.json y los bundles de .deploy/assets-<fecha>/huerfanos/
a /assets/dashboard/assets/.
"""
from __future__ import annotations

import argparse
import datetime as dt
import importlib.util
import io
import json
import pathlib
import shutil
import subprocess
import sys
import urllib.error
import urllib.request

ROOT = pathlib.Path(__file__).resolve().parents[1]
LOCAL_DIR = ROOT / "assets" / "dashboard"
LOCAL_MANIFEST = LOCAL_DIR / ".vite" / "manifest.json"
REMOTE_DIR = "/assets/dashboard"
REMOTE_MANIFEST = REMOTE_DIR + "/.vite/manifest.json"
BASE_URL = "https://vogelconsultoria.com.ar/mantenimiento/"

spec = importlib.util.spec_from_file_location("ferozo_ftps", ROOT / "scripts" / "ferozo-ftps.py")
ferozo = importlib.util.module_from_spec(spec)
sys.modules["ferozo_ftps"] = ferozo
spec.loader.exec_module(ferozo)


def fallar(mensaje: str) -> None:
    print(f"\nERROR: {mensaje}")
    sys.exit(1)


def git(*args: str) -> str:
    resultado = subprocess.run(["git", *args], cwd=ROOT, capture_output=True, text=True)
    if resultado.returncode != 0:
        fallar(f"git {' '.join(args)}: {resultado.stderr.strip()}")
    return resultado.stdout.strip()


def referencias(manifest: dict) -> set[str]:
    archivos: set[str] = set()
    for entrada in manifest.values():
        for clave in ("file", "css", "assets"):
            valor = entrada.get(clave)
            if isinstance(valor, str):
                archivos.add(valor)
            elif isinstance(valor, list):
                archivos.update(item for item in valor if isinstance(item, str))
    return archivos


def verificar_checkout() -> str:
    print("== 1. Checkout")
    rama = git("rev-parse", "--abbrev-ref", "HEAD")
    if rama != "main":
        fallar(f"estas en '{rama}'. Hace: git switch main")
    if git("status", "--porcelain"):
        fallar("hay cambios locales sin commitear. Commitealos o descartalos antes de deployar.")
    git("fetch", "origin", "main")
    local, remoto = git("rev-parse", "HEAD"), git("rev-parse", "origin/main")
    if local != remoto:
        if git("merge-base", "HEAD", "origin/main") == local:
            print("   main local atrasado: git pull --ff-only")
            git("pull", "--ff-only")
        else:
            fallar("main local tiene commits que no estan en origin/main. Pushealos primero.")
    sha = git("rev-parse", "--short", "HEAD")
    print(f"   OK main @ {sha} — {git('log', '-1', '--format=%s')}")
    return sha


def verificar_build() -> None:
    print("== 2. Bundles vs source")
    npm = shutil.which("npm") or shutil.which("npm.cmd")
    if not npm or not (ROOT / "frontend" / "node_modules").exists():
        print("   AVISO: sin npm/node_modules, salteo verify:build-sync (los bundles vienen del commit).")
        return
    resultado = subprocess.run([npm, "run", "verify:build-sync"], cwd=ROOT / "frontend", capture_output=True, text=True)
    if resultado.returncode != 0:
        print(resultado.stdout[-2000:], resultado.stderr[-2000:])
        fallar("los bundles versionados no coinciden con el source. Reconstrui y commitea assets/dashboard.")
    print("   OK BUILD_SYNC")


def bajar(cliente, remoto: str) -> bytes:
    buffer = io.BytesIO()
    cliente.retrbinary(f"RETR {remoto}", buffer.write)
    return buffer.getvalue()


def listar_remoto(cliente) -> set[str]:
    try:
        return {pathlib.PurePosixPath(n).name for n in cliente.nlst(REMOTE_DIR + "/assets")}
    except Exception:  # noqa: BLE001
        return set()


def http_status(url: str) -> int:
    peticion = urllib.request.Request(url, method="HEAD", headers={"Cache-Control": "no-cache"})
    try:
        with urllib.request.urlopen(peticion, timeout=60) as respuesta:
            return respuesta.status
    except urllib.error.HTTPError as error:
        return error.code
    except Exception:  # noqa: BLE001
        return 0


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--dry-run", action="store_true", help="Solo muestra el plan; no toca produccion.")
    parser.add_argument("--yes", action="store_true", help="No pide confirmacion.")
    parser.add_argument("--credentials", default=str(ROOT / ".ferozo-credentials"))
    args = parser.parse_args()

    sha = verificar_checkout()
    verificar_build()

    nuevo_bytes = LOCAL_MANIFEST.read_bytes()
    nuevo = json.loads(nuevo_bytes)
    refs_nuevas = referencias(nuevo)
    faltan_local = [r for r in refs_nuevas if not (LOCAL_DIR / r).is_file()]
    if faltan_local:
        fallar(f"el manifest local referencia archivos que no existen: {faltan_local}")

    print("== 3. Estado de produccion")
    cliente = ferozo.connect(pathlib.Path(args.credentials))
    try:
        actual_bytes = bajar(cliente, REMOTE_MANIFEST)
        refs_actuales = referencias(json.loads(actual_bytes))
        en_remoto = listar_remoto(cliente)

        if actual_bytes == nuevo_bytes:
            print("   Produccion ya tiene este manifest. Nada que subir.")
            return 0

        a_subir = sorted(r for r in refs_nuevas if pathlib.PurePosixPath(r).name not in en_remoto)
        huerfanos = sorted(refs_actuales - refs_nuevas)
        print(f"   Bundles en manifest nuevo : {len(refs_nuevas)}")
        print(f"   A subir (nuevos)          : {len(a_subir)}")
        for r in a_subir:
            print(f"     + {r}")
        print(f"   Huerfanos a borrar al final: {len(huerfanos)}")
        for r in huerfanos:
            print(f"     - {r}")

        if args.dry_run:
            print("\nDRY-RUN: no se toco produccion.")
            return 0
        if not args.yes:
            if input(f"\n¿Desplegar assets de {sha} a PRODUCCION? (si/no): ").strip().lower() not in ("si", "sí", "s", "y", "yes"):
                print("Cancelado.")
                return 1

        respaldo = ROOT / ".deploy" / f"assets-{dt.datetime.now():%Y%m%d-%H%M%S}-{sha}"
        (respaldo / "huerfanos" / "assets").mkdir(parents=True, exist_ok=True)
        (respaldo / "manifest.json").write_bytes(actual_bytes)
        print(f"== 5. Respaldo del manifest actual en {respaldo.relative_to(ROOT)}")

        print("== 6. Subiendo bundles")
        for r in a_subir:
            with (LOCAL_DIR / r).open("rb") as handle:
                cliente.storbinary(f"STOR {REMOTE_DIR}/{r}", handle, blocksize=256 * 1024)
            print(f"   SUBIDO {r}")

        print("== 7. Subiendo manifest")
        with LOCAL_MANIFEST.open("rb") as handle:
            cliente.storbinary(f"STOR {REMOTE_MANIFEST}", handle, blocksize=256 * 1024)
        if bajar(cliente, REMOTE_MANIFEST) != nuevo_bytes:
            fallar(f"el manifest remoto no coincide con el local despues de subirlo. Rollback: subir {respaldo / 'manifest.json'}")
        print("   OK manifest remoto = local")

        print("== 8. Verificacion HTTP")
        fallos = 0
        for r in sorted(refs_nuevas):
            codigo = http_status(BASE_URL + "assets/dashboard/" + r)
            if codigo != 200:
                fallos += 1
                print(f"   FALLA {codigo} {r}")
        if fallos:
            print(f"\n{fallos} bundle(s) no responden. NO se borran huerfanos.")
            print(f"Rollback: subir {respaldo / 'manifest.json'} a {REMOTE_MANIFEST}")
            return 1
        print(f"   OK {len(refs_nuevas)} bundles responden 200")

        print("== 9. Limpieza de huerfanos")
        for r in huerfanos:
            try:
                (respaldo / "huerfanos" / r).write_bytes(bajar(cliente, f"{REMOTE_DIR}/{r}"))
                cliente.delete(f"{REMOTE_DIR}/{r}")
                print(f"   BORRADO {r} (respaldado)")
            except Exception as error:  # noqa: BLE001
                print(f"   NO_SE_PUDO_BORRAR {r} ({type(error).__name__}) — queda en produccion, no rompe nada")
    finally:
        try:
            cliente.quit()
        except Exception:  # noqa: BLE001
            cliente.close()

    print(f"\nDEPLOY OK: assets de {sha} en produccion. Proba con Ctrl+F5:")
    print(f"  {BASE_URL}viajes/operativos")
    return 0


if __name__ == "__main__":
    sys.exit(main())
