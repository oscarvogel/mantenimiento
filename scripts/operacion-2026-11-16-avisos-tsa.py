#!/usr/bin/env python3
"""Sube, ejecuta y borra el script de limpieza de avisos obsoletos de TSA.

Uso:
    python scripts/operacion-2026-11-16-avisos-tsa.py
    python scripts/operacion-2026-11-16-avisos-tsa.py --apply

No imprime credenciales ni el token.
"""
from __future__ import annotations

import argparse
import datetime as dt
import importlib.util
import pathlib
import sys
import urllib.error
import urllib.parse
import urllib.request

ROOT = pathlib.Path(__file__).resolve().parents[1]
REMOTE_NAME = "avisos-limpieza.php"
URL_POR_DEFECTO = "https://vogelconsultoria.com.ar/mantenimiento/" + REMOTE_NAME

spec = importlib.util.spec_from_file_location("ferozo_ftps", ROOT / "scripts" / "ferozo-ftps.py")
ferozo = importlib.util.module_from_spec(spec)
sys.modules["ferozo_ftps"] = ferozo
spec.loader.exec_module(ferozo)


def borrar(cliente, destino: str) -> None:
    try:
        cliente.delete(destino)
        print(f"BORRADO {destino}")
    except Exception as error:  # noqa: BLE001
        print(f"NO_SE_PUDO_BORRAR {destino} ({type(error).__name__}: {error})")


def verificar_404(url: str) -> None:
    try:
        with urllib.request.urlopen(url, timeout=30) as respuesta:
            print(f"ADVERTENCIA: el helper sigue respondiendo HTTP {respuesta.status}; borrarlo a mano.")
    except urllib.error.HTTPError as error:
        print(f"VERIFICADO_HTTP_{error.code} {url}")
    except Exception as error:  # noqa: BLE001
        print(f"ADVERTENCIA: no se pudo verificar el borrado ({type(error).__name__}).")


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--apply", action="store_true", help="Ejecuta la limpieza. Sin esto solo simula.")
    parser.add_argument("--empresa-id", type=int, default=4)
    parser.add_argument("--token-file", default=str(ROOT / ".deploy" / "audit-pr494" / "migrate-token.txt"))
    parser.add_argument("--credentials", default=str(ROOT / ".ferozo-credentials"))
    parser.add_argument("--url", default=URL_POR_DEFECTO)
    parser.add_argument("--output")
    args = parser.parse_args()

    token = pathlib.Path(args.token_file).read_text(encoding="utf-8").strip()
    if not token:
        print("ERROR: el archivo de token esta vacio.")
        return 1

    marca = dt.datetime.now().strftime("%Y%m%d-%H%M%S")
    salida = pathlib.Path(args.output) if args.output else ROOT / "output" / "informe-tsa" / f"operacion-avisos-{marca}.json"
    salida.parent.mkdir(parents=True, exist_ok=True)

    parametros: dict[str, str] = {
        "empresa_id": str(args.empresa_id),
        "apply": "1" if args.apply else "0",
        "dry": "0" if args.apply else "1",
    }
    url = args.url + "?" + urllib.parse.urlencode(parametros)

    cliente = ferozo.connect(pathlib.Path(args.credentials))
    remoto = "/" + REMOTE_NAME
    try:
        with (ROOT / "scripts" / "operacion-2026-11-16-avisos-tsa-remote.php").open("rb") as handle:
            cliente.storbinary(f"STOR {remoto}", handle, blocksize=256 * 1024)
        print("HELPER_SUBIDO")

        peticion = urllib.request.Request(url, headers={"X-Migrate-Token": token})
        try:
            with urllib.request.urlopen(peticion, timeout=240) as respuesta:
                codigo = respuesta.status
                contenido = respuesta.read()
        except urllib.error.HTTPError as error:
            codigo = error.code
            contenido = error.read()

        salida.write_bytes(contenido)
        print(f"HTTP_{codigo} bytes={len(contenido)} guardado={salida}")
        print(contenido.decode("utf-8", errors="replace"))
        return 0 if codigo == 200 else 1
    finally:
        borrar(cliente, remoto)
        try:
            cliente.quit()
        except Exception:  # noqa: BLE001
            cliente.close()
        verificar_404(args.url)


if __name__ == "__main__":
    sys.exit(main())
