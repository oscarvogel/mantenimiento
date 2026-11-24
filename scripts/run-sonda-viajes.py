#!/usr/bin/env python3
"""Sonda de solo lectura contra produccion: sube el script, corre y borra todo.

Espejo de scripts/sonda-produccion.py, pero para la sonda de viajes operativos.
Solo SELECT: no escribe nada en la base.

Uso:
    python scripts/run-sonda-viajes.py --patente OIU270 [--numero 4]
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
SCRIPT_NAME = "sonda-viajes-operativos.php"
URL_BASE = "https://vogelconsultoria.com.ar/mantenimiento/" + SCRIPT_NAME

spec = importlib.util.spec_from_file_location("ferozo_ftps", ROOT / "scripts" / "ferozo-ftps.py")
ferozo = importlib.util.module_from_spec(spec)
sys.modules["ferozo_ftps"] = ferozo
spec.loader.exec_module(ferozo)


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--patente", default="")
    parser.add_argument("--numero", default="")
    parser.add_argument("--empresa-id", default="")
    parser.add_argument("--token-file", default=str(ROOT / ".env"))
    parser.add_argument("--credentials", default=str(ROOT / ".ferozo-credentials"))
    parser.add_argument("--keep", action="store_true", help="No borra la sonda al terminar.")
    args = parser.parse_args()

    token = ""
    for line in pathlib.Path(args.token_file).read_text(encoding="utf-8-sig").splitlines():
        if line.strip().startswith("MIGRATE_TOKEN"):
            token = line.split("=", 1)[1].strip().strip("'\"")
            break
    if not token:
        print("ERROR: no se encontro MIGRATE_TOKEN.")
        return 1

    params = {"format": "json"}
    if args.patente:
        params["patente"] = args.patente
    if args.numero:
        params["numero"] = args.numero
    if args.empresa_id:
        params["empresa_id"] = args.empresa_id
    url = URL_BASE + "?" + urllib.parse.urlencode(params)

    cliente = ferozo.connect(pathlib.Path(args.credentials))
    try:
        origen = ROOT / "scripts" / SCRIPT_NAME
        with origen.open("rb") as handle:
            cliente.storbinary(f"STOR /{SCRIPT_NAME}", handle, blocksize=256 * 1024)
        print("SONDA_SUBIDA")

        peticion = urllib.request.Request(url, headers={"X-Migrate-Token": token})
        try:
            with urllib.request.urlopen(peticion, timeout=120) as respuesta:
                codigo = respuesta.status
                cabeceras = dict(respuesta.headers.items())
                crudo = respuesta.read()
        except urllib.error.HTTPError as error:
            codigo = error.code
            cabeceras = dict(error.headers.items()) if error.headers else {}
            crudo = error.read()

        print(f"--- SONDA HTTP {codigo} ---")
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
