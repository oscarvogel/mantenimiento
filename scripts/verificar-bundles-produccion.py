#!/usr/bin/env python3
"""Verifica que cada bundle referenciado por el manifest de produccion exista.

Lee el manifest que se sirve por HTTP y pide cada archivo. Si falta alguno, el
frontend carga roto.
"""
from __future__ import annotations

import json
import urllib.request

BASE = "https://vogelconsultoria.com.ar/mantenimiento/"
FALLOS = 0


def pedir(url: str, metodo: str = "HEAD") -> tuple[int, bytes]:
    peticion = urllib.request.Request(url, method=metodo)
    try:
        with urllib.request.urlopen(peticion, timeout=60) as respuesta:
            return respuesta.status, respuesta.read()
    except urllib.error.HTTPError as error:
        return error.code, error.read()


def main() -> int:
    global FALLOS
    codigo, cuerpo = pedir(BASE + "assets/dashboard/.vite/manifest.json", "GET")
    print(f"MANIFEST HTTP {codigo}")
    if codigo != 200:
        return 1
    manifest = json.loads(cuerpo.decode("utf-8"))

    referencias: set[str] = set()
    for entrada in manifest.values():
        for clave in ("file", "css", "assets"):
            valor = entrada.get(clave)
            if isinstance(valor, str):
                referencias.add(valor)
            elif isinstance(valor, list):
                referencias.update(item for item in valor if isinstance(item, str))

    print(f"REFERENCIAS {len(referencias)}")
    for nombre in sorted(referencias):
        codigo, _ = pedir(BASE + "assets/dashboard/" + nombre)
        estado = "OK" if codigo == 200 else "FALLA"
        if codigo != 200:
            FALLOS += 1
        print(f"  {estado} {codigo} {nombre}")

    for ruta in ["/login", "/viajes/operativos/nuevo", "/mantenimiento/operativos/nuevo"]:
        codigo, _ = pedir(BASE.rstrip("/") + ruta)
        print(f"RUTA {codigo} {ruta}")
    print(f"FALLOS {FALLOS}")
    return 1 if FALLOS else 0


if __name__ == "__main__":
    raise SystemExit(main())
