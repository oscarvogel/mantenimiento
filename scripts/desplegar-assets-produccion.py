#!/usr/bin/env python3
"""Sube solo los assets del frontend a produccion y limpia los huerfanos.

El cambio es de frontend: el bundle y el manifest. El PHP no se toca.

El manifest se sube al final (nunca puede apuntar a un bundle que todavia no
esta) y los huerfanos se borran despues, comparando el manifest que sirve
produccion con el nuevo.
"""
from __future__ import annotations

import hashlib
import importlib.util
import json
import pathlib
import sys

ROOT = pathlib.Path(__file__).resolve().parents[1]

spec = importlib.util.spec_from_file_location("ferozo_ftps", ROOT / "scripts" / "ferozo-ftps.py")
ferozo = importlib.util.module_from_spec(spec)
sys.modules["ferozo_ftps"] = ferozo
spec.loader.exec_module(ferozo)

NUEVO = ROOT / "assets" / "dashboard"
MANIFEST_ACTUAL = ROOT / ".deploy" / "pr494-upload" / "assets" / "dashboard" / ".vite" / "manifest.json"


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
    cliente = ferozo.connect(ROOT / ".ferozo-credentials")
    try:
        bundles = sorted((NUEVO / "assets").glob("*"))
        for bundle in bundles:
            with bundle.open("rb") as handle:
                cliente.storbinary(f"STOR /assets/dashboard/assets/{bundle.name}", handle, blocksize=256 * 1024)
        print(f"BUNDLES_SUBIDOS {len(bundles)}")

        manifest = NUEVO / ".vite" / "manifest.json"
        with manifest.open("rb") as handle:
            cliente.storbinary("STOR /assets/dashboard/.vite/manifest.json", handle, blocksize=256 * 1024)
        print("MANIFEST_SUBIDO")

        huerfanos = sorted(referencias(MANIFEST_ACTUAL) - referencias(manifest))
        for nombre in huerfanos:
            try:
                cliente.delete("/assets/dashboard/" + nombre)
                print(f"  BORRADO {nombre}")
            except Exception as error:  # noqa: BLE001
                print(f"  NO_SE_PUDO_BORRAR {nombre} ({type(error).__name__})")
        print(f"HUERFANOS {len(huerfanos)}")
    finally:
        try:
            cliente.quit()
        except Exception:  # noqa: BLE001
            cliente.close()
    return 0


if __name__ == "__main__":
    sys.exit(main())
