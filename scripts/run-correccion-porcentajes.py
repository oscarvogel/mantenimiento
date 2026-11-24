#!/usr/bin/env python3
"""Correccion de porcentajes de liquidacion de chofer contra produccion Ferozo.

Sube el script a la raiz del release, lo ejecuta (por defecto DRY-RUN) y lo
borra. Mismo patron que scripts/run-sonda-viajes.py.

Uso:
    python scripts/run-correccion-porcentajes.py                  # dry-run
    python scripts/run-correccion-porcentajes.py --apply          # escribe
"""
from __future__ import annotations

import argparse
import importlib.util
import pathlib
import sys
import urllib.error
import urllib.parse
import urllib.request

ROOT = pathlib.Path(__file__).resolve().parents[1]
SCRIPT_NAME = "correccion-porcentajes-chofer-produccion.php"
URL_BASE = "https://vogelconsultoria.com.ar/mantenimiento/" + SCRIPT_NAME

spec = importlib.util.spec_from_file_location("ferozo_ftps", ROOT / "scripts" / "ferozo-ftps.py")
ferozo = importlib.util.module_from_spec(spec)
sys.modules["ferozo_ftps"] = ferozo
spec.loader.exec_module(ferozo)


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--apply", action="store_true", help="Escribe en la base. Sin esto es dry-run.")
    parser.add_argument("--empresa-id", default="")
    parser.add_argument("--usuario-id", default="")
    parser.add_argument("--porcentaje", default="")
    parser.add_argument("--vigente-desde", default="")
    parser.add_argument("--token-file", default=str(ROOT / ".env"))
    parser.add_argument("--credentials", default=str(ROOT / ".ferozo-credentials"))
    parser.add_argument("--keep", action="store_true", help="No borra el script al terminar.")
    args = parser.parse_args()

    token = ""
    for line in pathlib.Path(args.token_file).read_text(encoding="utf-8-sig").splitlines():
        if line.strip().startswith("MIGRATE_TOKEN"):
            token = line.split("=", 1)[1].strip().strip("'\"")
            break
    if not token:
        print("ERROR: no se encontro MIGRATE_TOKEN.")
        return 1

    params: dict[str, str] = {}
    if args.apply:
        params["apply"] = "1"
    if args.empresa_id:
        params["empresa_id"] = args.empresa_id
    if args.usuario_id:
        params["usuario_id"] = args.usuario_id
    if args.porcentaje:
        params["porcentaje"] = args.porcentaje
    if args.vigente_desde:
        params["vigente_desde"] = args.vigente_desde
    url = URL_BASE + ("?" + urllib.parse.urlencode(params) if params else "")

    cliente = ferozo.connect(pathlib.Path(args.credentials))
    try:
        origen = ROOT / "scripts" / SCRIPT_NAME
        with origen.open("rb") as handle:
            cliente.storbinary(f"STOR /{SCRIPT_NAME}", handle, blocksize=256 * 1024)
        print("SCRIPT_SUBIDO " + SCRIPT_NAME)
        print("MODO " + ("APLICA" if args.apply else "DRY_RUN"))
        print("URL " + url)

        peticion = urllib.request.Request(url, headers={"X-Migrate-Token": token})
        try:
            with urllib.request.urlopen(peticion, timeout=180) as respuesta:
                codigo = respuesta.status
                cabeceras = dict(respuesta.headers.items())
                crudo = respuesta.read()
        except urllib.error.HTTPError as error:
            codigo = error.code
            cabeceras = dict(error.headers.items()) if error.headers else {}
            crudo = error.read()

        print(f"--- HTTP {codigo} ---")
        for clave, valor in cabeceras.items():
            print(f"  {clave}: {valor}")
        print("--- CUERPO ---")
        print(crudo.decode("utf-8", errors="replace"))
        print("--- FIN CUERPO ---")
    finally:
        if not args.keep:
            try:
                cliente.delete("/" + SCRIPT_NAME)
                print("BORRADO " + SCRIPT_NAME)
            except Exception as error:  # noqa: BLE001
                print(f"NO_SE_PUDO_BORRAR ({type(error).__name__}: {error})")
        try:
            cliente.quit()
        except Exception:  # noqa: BLE001
            cliente.close()
    return 0


if __name__ == "__main__":
    sys.exit(main())
