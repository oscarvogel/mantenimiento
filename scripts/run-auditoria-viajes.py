#!/usr/bin/env python3
"""Auditoria de SOLO LECTURA de los viajes operativos contra produccion Ferozo.

Sube el script a la raiz del release, lo ejecuta (siempre en modo lectura), baja
el informe JSON a disco y borra el script del release.

Uso:
    python scripts/run-auditoria-viajes.py
    python scripts/run-auditoria-viajes.py --empresa-id 2
    python scripts/run-auditoria-viajes.py --viaje 4
"""
from __future__ import annotations

import argparse
import importlib.util
import json
import pathlib
import sys
import urllib.error
import urllib.parse
import urllib.request

ROOT = pathlib.Path(__file__).resolve().parents[1]
SCRIPT_NAME = "auditoria-produccion-viajes.php"
URL_BASE = "https://vogelconsultoria.com.ar/mantenimiento/" + SCRIPT_NAME
SALIDA_DIR = ROOT / "tmp"

spec = importlib.util.spec_from_file_location("ferozo_ftps", ROOT / "scripts" / "ferozo-ftps.py")
ferozo = importlib.util.module_from_spec(spec)
sys.modules["ferozo_ftps"] = ferozo
spec.loader.exec_module(ferozo)


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--empresa-id", default="")
    parser.add_argument("--viaje", default="")
    parser.add_argument("--salida", default="")
    parser.add_argument("--token-file", default=str(ROOT / ".env"))
    parser.add_argument("--credentials", default=str(ROOT / ".ferozo-credentials"))
    parser.add_argument("--keep", action="store_true", help="No borra el script del release al terminar.")
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
    if args.empresa_id:
        params["empresa_id"] = args.empresa_id
    if args.viaje:
        params["viaje"] = args.viaje
    url = URL_BASE + ("?" + urllib.parse.urlencode(params) if params else "")

    cliente = ferozo.connect(pathlib.Path(args.credentials))
    try:
        origen = ROOT / "scripts" / SCRIPT_NAME
        with origen.open("rb") as handle:
            cliente.storbinary(f"STOR /{SCRIPT_NAME}", handle, blocksize=256 * 1024)
        print("SCRIPT_SUBIDO " + SCRIPT_NAME)
        print("URL " + url)

        peticion = urllib.request.Request(url, headers={"X-Migrate-Token": token})
        try:
            with urllib.request.urlopen(peticion, timeout=300) as respuesta:
                codigo = respuesta.status
                crudo = respuesta.read()
        except urllib.error.HTTPError as error:
            codigo = error.code
            crudo = error.read()

        print(f"--- HTTP {codigo} ---")
        cuerpo = crudo.decode("utf-8", errors="replace")
        if codigo != 200:
            print(cuerpo)
            return 1

        SALIDA_DIR.mkdir(parents=True, exist_ok=True)
        destino = pathlib.Path(args.salida) if args.salida else SALIDA_DIR / "auditoria-produccion-viajes.json"
        destino.write_text(cuerpo, encoding="utf-8")
        print("INFORME " + str(destino))

        informe = json.loads(cuerpo)
        print("--- RESUMEN ---")
        print(json.dumps({
            "empresa": informe.get("empresa"),
            "alcance": informe.get("alcance"),
            "porcentaje_defecto_empresa": informe.get("porcentaje_defecto_empresa"),
            "panorama": informe.get("panorama"),
            "combustible": informe.get("combustible"),
            "gastos": informe.get("gastos"),
            "caja": informe.get("caja"),
            "otros_haberes": informe.get("otros_haberes"),
            "economia": {"totales": (informe.get("economia") or {}).get("totales")},
            "alertas": informe.get("alertas"),
        }, ensure_ascii=False, indent=2))
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
