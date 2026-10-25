#!/usr/bin/env python3
"""Verifica (sin imprimir valores) el porton de migraciones de produccion.

Solo informa que claves existen en el .env de produccion.
"""
from __future__ import annotations

import importlib.util
import io
import pathlib
import sys

ROOT = pathlib.Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location("ferozo_ftps", ROOT / "scripts" / "ferozo-ftps.py")
ferozo = importlib.util.module_from_spec(spec)
sys.modules["ferozo_ftps"] = ferozo
spec.loader.exec_module(ferozo)

CLAVES = [
    "CI_ENVIRONMENT",
    "MIGRATE_TOKEN",
    "database.default.hostname",
    "database.default.database",
    "database.default.username",
    "app.baseURL",
    "imports.privatePath",
    "uploads.privatePath",
]


def main() -> int:
    client = ferozo.connect(ROOT / ".ferozo-credentials")
    try:
        buffer = io.BytesIO()
        client.retrbinary("RETR /.env", buffer.write)
        texto = buffer.getvalue().decode("utf-8", errors="replace")
    except Exception as error:  # noqa: BLE001
        print(f"NO_SE_PUDO_LEER .env ({type(error).__name__})")
        return 1
    finally:
        try:
            client.quit()
        except Exception:  # noqa: BLE001
            client.close()

    valores: dict[str, str] = {}
    for linea in texto.splitlines():
        linea = linea.strip()
        if not linea or linea.startswith("#") or "=" not in linea:
            continue
        clave, _, valor = linea.partition("=")
        clave = clave.strip()
        valor = valor.strip().strip("'\"")
        valores[clave] = valor

    for clave in CLAVES:
        if clave not in valores:
            print(f"AUSENTE  {clave}")
            continue
        valor = valores[clave]
        if clave in {"MIGRATE_TOKEN", "database.default.password"}:
            print(f"PRESENTE {clave} (longitud {len(valor)})")
            continue
        print(f"PRESENTE {clave} = {valor}")

    if "MIGRATE_TOKEN" in valores:
        token = valores["MIGRATE_TOKEN"]
        (ROOT / ".deploy" / "audit-pr494" / "migrate-token.txt").parent.mkdir(parents=True, exist_ok=True)
        (ROOT / ".deploy" / "audit-pr494" / "migrate-token.txt").write_text(token, encoding="utf-8")
        print("TOKEN_GUARDADO .deploy/audit-pr494/migrate-token.txt")
    return 0


if __name__ == "__main__":
    sys.exit(main())
