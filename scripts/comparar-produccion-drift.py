#!/usr/bin/env python3
"""Compara el app/ descargado de produccion contra el arbol local.

No imprime contenido de archivos: solo nombres y estado. Sirve para ver el
drift antes de subir nada.
"""
from __future__ import annotations

import hashlib
import pathlib
import sys

ROOT = pathlib.Path(__file__).resolve().parents[1]
LOCAL = ROOT / "app"
REMOTE = ROOT / ".deploy" / "audit-pr494" / "app"


def digest(path: pathlib.Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def main() -> int:
    if not REMOTE.exists():
        print("No hay copia de produccion para comparar.")
        return 1

    only_remote: list[str] = []
    only_local: list[str] = []
    different: list[str] = []
    same = 0

    for path in sorted(REMOTE.rglob("*")):
        if not path.is_file():
            continue
        relative = path.relative_to(REMOTE)
        counterpart = LOCAL / relative
        if not counterpart.exists():
            only_remote.append(str(relative).replace("\\", "/"))
            continue
        if digest(path) != digest(counterpart):
            different.append(str(relative).replace("\\", "/"))
        else:
            same += 1

    for path in sorted(LOCAL.rglob("*")):
        if not path.is_file():
            continue
        relative = path.relative_to(LOCAL)
        if not (REMOTE / relative).exists():
            only_local.append(str(relative).replace("\\", "/"))

    print(f"IDENTICOS {same}")
    print(f"DIFERENTES {len(different)}")
    for name in different:
        print(f"  D {name}")
    print(f"SOLO_EN_PRODUCCION {len(only_remote)}")
    for name in only_remote[:40]:
        print(f"  P {name}")
    print(f"SOLO_EN_LOCAL {len(only_local)}")
    for name in only_local[:40]:
        print(f"  L {name}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
