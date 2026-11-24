#!/usr/bin/env python3
"""Sube el script temporal de verificacion del modulo de Combustible al release
de produccion, lo ejecuta (siempre en modo lectura), guarda el JSON en disco y
borra el script del release.

Uso:
    python tmp/run-verificar-modulo-combustible.py --empresa-id 4 --dias 30
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
SCRIPT_NAME = "verificar-modulo-combustible.php"
URL_BASE = "https://vogelconsultoria.com.ar/mantenimiento/" + SCRIPT_NAME
ORIGEN = ROOT / "scripts" / SCRIPT_NAME

spec = importlib.util.spec_from_file_location("ferozo_ftps", ROOT / "scripts" / "ferozo-ftps.py")
ferozo = importlib.util.module_from_spec(spec)
sys.modules["ferozo_ftps"] = ferozo
spec.loader.exec_module(ferozo)


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--empresa-id", default="4")
    parser.add_argument("--dias", default="30")
    parser.add_argument("--salida", default=str(ROOT / "tmp" / "verificacion-modulo-combustible.json"))
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

    url = URL_BASE + "?" + urllib.parse.urlencode({"empresa_id": args.empresa_id, "dias": args.dias})

    cliente = ferozo.connect(pathlib.Path(args.credentials))
    try:
        with ORIGEN.open("rb") as handle:
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

        destino = pathlib.Path(args.salida)
        destino.write_text(cuerpo, encoding="utf-8")
        print("INFORME " + str(destino))

        informe = json.loads(cuerpo)
        print("--- RESUMEN ---")
        print(json.dumps({
            "ok": informe.get("ok"),
            "motor": informe.get("motor"),
            "empresa": informe.get("empresa"),
            "dias": informe.get("dias"),
            "panel_totales": informe.get("panel_totales"),
            "tabla_total_empresa": informe.get("tabla_total_empresa"),
            "tabla_en_la_ventana_del_panel": informe.get("tabla_en_la_ventana_del_panel"),
            "coincide_panel_con_tabla": informe.get("coincide_panel_con_tabla"),
            "coincide_total": informe.get("coincide_total"),
            "conciliacion_totales": informe.get("conciliacion_totales"),
            "error": informe.get("error"),
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
