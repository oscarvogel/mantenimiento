#!/usr/bin/env python3
"""Audita archivos puntuales de produccion (raiz y assets) sin subirlos.

Descarga solo lo pedido y compara contra el arbol local.
"""
from __future__ import annotations

import hashlib
import importlib.util
import pathlib
import sys

ROOT = pathlib.Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location("ferozo_ftps", ROOT / "scripts" / "ferozo-ftps.py")
ferozo = importlib.util.module_from_spec(spec)
sys.modules["ferozo_ftps"] = ferozo
spec.loader.exec_module(ferozo)

TARGETS = [
    ("index.php", "index.php"),
    (".htaccess", ".htaccess"),
    ("spark", "spark"),
    ("composer.json", "composer.json"),
    ("composer.lock", "composer.lock"),
    ("assets/dashboard/.vite/manifest.json", "assets/dashboard/.vite/manifest.json"),
]


def digest_bytes(payload: bytes) -> str:
    return hashlib.sha256(payload).hexdigest()


def digest_file(path: pathlib.Path) -> str:
    return digest_bytes(path.read_bytes())


def main() -> int:
    credentials = ROOT / ".ferozo-credentials"
    client = ferozo.connect(credentials)
    local_checkout = ROOT
    try:
        for remote_path, local_relative in TARGETS:
            remote_full = "/" + remote_path
            local_path = local_checkout / local_relative
            try:
                import io

                buffer = io.BytesIO()
                client.retrbinary(f"RETR {remote_full}", buffer.write)
                remote_bytes = buffer.getvalue()
            except Exception as error:  # noqa: BLE001 - el informe necesita el motivo
                print(f"AUSENTE {remote_path} ({type(error).__name__})")
                continue
            if not local_path.exists():
                print(f"SOLO_REMOTO {remote_path} bytes={len(remote_bytes)}")
                continue
            local_hash = digest_file(local_path)
            remote_hash = digest_bytes(remote_bytes)
            estado = "IGUAL" if local_hash == remote_hash else "DISTINTO"
            print(f"{estado} {remote_path} remoto={len(remote_bytes)}B local={local_path.stat().st_size}B")
    finally:
        try:
            client.quit()
        except Exception:  # noqa: BLE001
            client.close()
    return 0


if __name__ == "__main__":
    sys.exit(main())
