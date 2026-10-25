#!/usr/bin/env python3
"""Lista los bundles que quedan huerfanos con el manifest nuevo.

Solo borra lo que el manifest viejo referenciaba y el nuevo no: no toca
residuo de builds anteriores, que es de otro deploy.
"""
from __future__ import annotations

import json
import pathlib
import sys

ROOT = pathlib.Path(__file__).resolve().parents[1]
OLD = ROOT / ".deploy" / "pr494-backup" / "dashboard" / ".vite" / "manifest.json"
NEW = ROOT / ".deploy" / "pr494-upload" / "assets" / "dashboard" / ".vite" / "manifest.json"
PROD_DIR = ROOT / ".deploy" / "pr494-backup" / "dashboard"


def referencias(manifest: pathlib.Path) -> set[str]:
    datos = json.loads(manifest.read_text(encoding="utf-8"))
    archivos: set[str] = set()
    for entrada in datos.values():
        for clave in ("file", "css", "assets"):
            valor = entrada.get(clave)
            if isinstance(valor, str):
                archivos.add(valor)
            elif isinstance(valor, list):
                archivos.update(item for item in valor if isinstance(item, str))
    return archivos


def main() -> int:
    viejos = referencias(OLD)
    nuevos = referencias(NEW)
    huerfanos = sorted(viejos - nuevos)
    print(f"REFERENCIADOS_VIEJOS {len(viejos)}")
    print(f"REFERENCIADOS_NUEVOS {len(nuevos)}")
    print(f"HUERFANOS {len(huerfanos)}")
    for nombre in huerfanos:
        existe = (PROD_DIR / nombre).exists()
        print(f"  {'OK ' if existe else 'NO_EXISTE'} {nombre}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
