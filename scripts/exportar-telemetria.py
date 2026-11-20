#!/usr/bin/env python3
"""Sube, ejecuta y borra el helper de exportacion de telemetria (solo lectura).

Baja un ZIP con un CSV por tabla (telemetria Wialon + equipos, OT, planes,
lecturas y viajes) y un manifest.json, para analizar alertas e informes.

Uso:
    python scripts/exportar-telemetria.py --empresa-id 4
    python scripts/exportar-telemetria.py --empresa-id 4 --dias 30 --crudos

No imprime credenciales ni el token.
"""
from __future__ import annotations

import argparse
import datetime as dt
import hashlib
import importlib.util
import pathlib
import sys
import urllib.error
import urllib.parse
import urllib.request
import zipfile

ROOT = pathlib.Path(__file__).resolve().parents[1]
REMOTE_NAME = "exportar-telemetria.php"
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
        print(f"NO_SE_PUDO_BORRAR {destino} ({type(error).__name__}: {error}) -> borrarlo a mano")


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
    parser.add_argument("--empresa-id", type=int, required=True)
    parser.add_argument("--dias", type=int, default=90, help="Dias de telemetria (muestras y buckets). Default 90.")
    parser.add_argument("--crudos", action="store_true", help="Incluye mensajes_fuente_json de los buckets (pesado).")
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
    salida = (
        pathlib.Path(args.output)
        if args.output
        else ROOT / "output" / "telemetria" / f"telemetria-empresa{args.empresa_id}-{marca}.zip"
    )
    salida.parent.mkdir(parents=True, exist_ok=True)

    parametros = {"empresa_id": str(args.empresa_id), "dias": str(args.dias)}
    if args.crudos:
        parametros["crudos"] = "1"
    url = args.url + "?" + urllib.parse.urlencode(parametros)

    cliente = ferozo.connect(pathlib.Path(args.credentials))
    remoto = "/" + REMOTE_NAME
    try:
        with (ROOT / "scripts" / "exportar-telemetria-remote.php").open("rb") as handle:
            cliente.storbinary(f"STOR {remoto}", handle, blocksize=256 * 1024)
        print("HELPER_SUBIDO")
        # La conexion FTP puede vencer mientras corre la exportacion; se reabre para borrar.
        try:
            cliente.quit()
        except Exception:  # noqa: BLE001
            cliente.close()

        peticion = urllib.request.Request(url, headers={"X-Migrate-Token": token})
        try:
            with urllib.request.urlopen(peticion, timeout=900) as respuesta:
                codigo = respuesta.status
                sha_servidor = respuesta.headers.get("X-Export-Sha256", "")
                with salida.open("wb") as destino:
                    while bloque := respuesta.read(1024 * 1024):
                        destino.write(bloque)
        except urllib.error.HTTPError as error:
            print(f"HTTP_{error.code}")
            print(error.read().decode("utf-8", errors="replace"))
            return 1

        sha_local = hashlib.sha256(salida.read_bytes()).hexdigest()
        print(f"HTTP_{codigo} bytes={salida.stat().st_size} guardado={salida}")
        if sha_servidor and sha_servidor != sha_local:
            print(f"ERROR: SHA-256 distinto (servidor {sha_servidor}, local {sha_local}). Descarga corrupta.")
            return 1
        print(f"SHA256_OK {sha_local}")

        with zipfile.ZipFile(salida) as zf:
            if zf.testzip() is not None:
                print("ERROR: el ZIP esta danado.")
                return 1
            for info in zf.infolist():
                print(f"  {info.filename:40} {info.file_size:>12,} bytes")
        return 0
    finally:
        cliente = ferozo.connect(pathlib.Path(args.credentials))
        borrar(cliente, remoto)
        try:
            cliente.quit()
        except Exception:  # noqa: BLE001
            cliente.close()
        verificar_404(args.url)


if __name__ == "__main__":
    sys.exit(main())
