#!/usr/bin/env python3
"""Sonda de solo lectura contra produccion: sube el script, corre y borra todo.

El porton (sonda-importacion.php) exige el header X-Migrate-Token y
CI_ENVIRONMENT=production. No escribe nada en la base: solo SELECT.

Ciclo: sube la sonda a la raiz del release y los CSV a `writable/sonda/` (privado),
llama al porton e imprime la salida, y borra todo pase lo que pase.

Uso:
    python scripts/sonda-produccion.py [opciones]

Opciones:
    --csv RUTA        CSV a validar (repetible). Por defecto, todos los CSV de
                      `docs/carga_semis/`.
    --token-file RUTA Archivo con el token de migraciones.
                      Por defecto `.deploy/audit-pr494/migrate-token.txt`.
    --url URL         URL del porton.
                      Por defecto https://vogelconsultoria.com.ar/mantenimiento/sonda-importacion.php
    --credentials RUTA
                      Credenciales FTPS. Por defecto `.ferozo-credentials`.
"""
from __future__ import annotations

import argparse
import importlib.util
import pathlib
import sys
import urllib.error
import urllib.request

ROOT = pathlib.Path(__file__).resolve().parents[1]
URL_POR_DEFECTO = "https://vogelconsultoria.com.ar/mantenimiento/sonda-importacion.php"

spec = importlib.util.spec_from_file_location("ferozo_ftps", ROOT / "scripts" / "ferozo-ftps.py")
ferozo = importlib.util.module_from_spec(spec)
sys.modules["ferozo_ftps"] = ferozo
spec.loader.exec_module(ferozo)


def subir(cliente, origen: pathlib.Path, destino: str) -> None:
    with origen.open("rb") as handle:
        cliente.storbinary(f"STOR {destino}", handle, blocksize=256 * 1024)


def borrar(cliente, destino: str) -> None:
    try:
        cliente.delete(destino)
        print(f"BORRADO {destino}")
    except Exception as error:  # noqa: BLE001
        print(f"NO_SE_PUDO_BORRAR {destino} ({type(error).__name__}: {error})")


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--csv", action="append", default=[])
    parser.add_argument("--token-file", default=str(ROOT / ".deploy" / "audit-pr494" / "migrate-token.txt"))
    parser.add_argument("--url", default=URL_POR_DEFECTO)
    parser.add_argument("--credentials", default=str(ROOT / ".ferozo-credentials"))
    args = parser.parse_args()

    token = pathlib.Path(args.token_file).read_text(encoding="utf-8").strip()
    if not token:
        print("ERROR: el archivo de token esta vacio.")
        return 1

    csvs = [pathlib.Path(c) for c in args.csv]
    if not csvs:
        csvs = sorted((ROOT / "docs" / "carga_semis").glob("*.csv"))
    if not csvs:
        print("ERROR: no hay CSV para validar.")
        return 1

    cliente = ferozo.connect(pathlib.Path(args.credentials))
    subidos: list[str] = []
    try:
        # Directorio privado para los CSV (writable esta protegido en produccion).
        try:
            cliente.mkd("/writable/sonda")
        except Exception:  # noqa: BLE001
            pass

        subir(cliente, ROOT / "scripts" / "sonda-importacion.php", "/sonda-importacion.php")
        print("SONDA_SUBIDA")
        for csv in csvs:
            destino = f"/writable/sonda/{csv.name}"
            subir(cliente, csv, destino)
            subidos.append(destino)
            print(f"CSV_SUBIDO {destino}")

        peticion = urllib.request.Request(args.url, headers={"X-Migrate-Token": token})
        try:
            with urllib.request.urlopen(peticion, timeout=180) as respuesta:
                codigo = respuesta.status
                texto = respuesta.read().decode("utf-8", errors="replace")
        except urllib.error.HTTPError as error:
            codigo = error.code
            texto = error.read().decode("utf-8", errors="replace")

        print(f"--- SONDA HTTP {codigo} ---")
        print(texto)
    finally:
        for destino in reversed(subidos):
            borrar(cliente, destino)
        borrar(cliente, "/sonda-importacion.php")
        try:
            cliente.rmd("/writable/sonda")
            print("BORRADO /writable/sonda")
        except Exception:  # noqa: BLE001
            pass
        try:
            cliente.quit()
        except Exception:  # noqa: BLE001
            cliente.close()
    return 0


if __name__ == "__main__":
    sys.exit(main())
