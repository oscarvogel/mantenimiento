#!/usr/bin/env python3
"""Aplica las migraciones pendientes en produccion por el porton HTTP.

Sube `migrate.php` a la raiz del webroot, consulta el estado, corre las
migraciones y borra el archivo, pase lo que pase.
"""
from __future__ import annotations

import importlib.util
import pathlib
import sys
import urllib.error
import urllib.request

ROOT = pathlib.Path(__file__).resolve().parents[1]
URL = "https://vogelconsultoria.com.ar/mantenimiento/migrate.php"

spec = importlib.util.spec_from_file_location("ferozo_ftps", ROOT / "scripts" / "ferozo-ftps.py")
ferozo = importlib.util.module_from_spec(spec)
sys.modules["ferozo_ftps"] = ferozo
spec.loader.exec_module(ferozo)


def llamar(token: str, con_estado: bool) -> tuple[int, str]:
    url = URL + ("?status=1" if con_estado else "")
    peticion = urllib.request.Request(url, headers={"X-Migrate-Token": token})
    try:
        with urllib.request.urlopen(peticion, timeout=180) as respuesta:
            return respuesta.status, respuesta.read().decode("utf-8", errors="replace")
    except urllib.error.HTTPError as error:
        return error.code, error.read().decode("utf-8", errors="replace")


def subir_porton(cliente) -> None:
    origen = ROOT / "scripts" / "migrate.php"
    with origen.open("rb") as handle:
        cliente.storbinary("STOR /migrate.php", handle, blocksize=256 * 1024)


def borrar_porton(cliente) -> None:
    try:
        cliente.delete("/migrate.php")
        print("PORTON_BORRADO")
    except Exception as error:  # noqa: BLE001
        print(f"NO_SE_PUDO_BORRAR {type(error).__name__}: {error}")


def main() -> int:
    token = (ROOT / ".deploy" / "audit-pr494" / "migrate-token.txt").read_text(encoding="utf-8").strip()
    cliente = ferozo.connect(ROOT / ".ferozo-credentials")
    try:
        subir_porton(cliente)
        print("PORTON_SUBIDO")
        estado, texto = llamar(token, con_estado=True)
        print(f"--- ESTADO HTTP {estado} ---")
        print(texto)
        if "?status=1" in texto and "PENDIENTE" not in texto.upper() and "PENDIENT" not in texto.upper():
            pass
        codigo, salida = llamar(token, con_estado=False)
        print(f"--- MIGRACION HTTP {codigo} ---")
        print(salida)
    finally:
        borrar_porton(cliente)
        try:
            cliente.quit()
        except Exception:  # noqa: BLE001
            cliente.close()
    return 0


if __name__ == "__main__":
    sys.exit(main())
