#!/usr/bin/env python3
"""Sube el release de `main` a produccion (Ferozo por FTPS) en orden controlado.

El orden no es estetico: hay pares de archivos de los que uno depende del otro
(interfaz/implementacion, comando/caso de uso, clase/cableado). Subirlos en el
orden equivocado deja una ventana de segundos en la que una peticion puede
caer con una version de cada lado.

Orden:
  1. Todas las clases nuevas y las actualizadas, salvo el par de viaje y el
     cableado. Son aditivas: no cambian firmas que el codigo viejo invoque.
  2. El par comando/caso de uso del alta de viaje, seguido. Entre los dos hay
     una ventana de milisegundos.
  3. El cableado (Routes y Services), que ya puede referenciar todo.
  4. Los bundles nuevos y, al final, el manifest.

No toca .env, vendor/, writable/, ni los archivos de la raiz.
"""
from __future__ import annotations

import hashlib
import importlib.util
import pathlib
import sys
import time

ROOT = pathlib.Path(__file__).resolve().parents[1]
PAQUETE = ROOT / ".deploy" / "pr494-upload"
PROD_COPY = ROOT / ".deploy" / "audit-pr494" / "app"

spec = importlib.util.spec_from_file_location("ferozo_ftps", ROOT / "scripts" / "ferozo-ftps.py")
ferozo = importlib.util.module_from_spec(spec)
sys.modules["ferozo_ftps"] = ferozo
spec.loader.exec_module(ferozo)

# Pares que no pueden separarse: el segundo necesita al primero.
PAR_CRITICO = [
    ("Application/Trips/CreateTripCommand.php", "Application/Trips/CreateTrip.php"),
]
CABLEADO = ["Config/Routes.php", "Config/Services.php"]


def sha256(path: pathlib.Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def clasificacion() -> tuple[list[tuple[str, pathlib.Path]], list[tuple[str, pathlib.Path]]]:
    """Separa los archivos del paquete en (nuevos, modificados) contra produccion."""
    nuevos: list[tuple[str, pathlib.Path]] = []
    modificados: list[tuple[str, pathlib.Path]] = []
    for path in sorted((PAQUETE / "app").rglob("*")):
        if not path.is_file():
            continue
        relativo = path.relative_to(PAQUETE / "app").as_posix()
        en_produccion = PROD_COPY / relativo
        if not en_produccion.exists():
            nuevos.append((relativo, path))
        elif sha256(en_produccion) != sha256(path):
            modificados.append((relativo, path))
    return nuevos, modificados


def subir(cliente, destino: str, origen: pathlib.Path) -> None:
    with origen.open("rb") as handle:
        cliente.storbinary(f"STOR {destino}", handle, blocksize=256 * 1024)


def main() -> int:
    nuevos, modificados = clasificacion()
    exclusiones = {relativo for par in PAR_CRITICO for relativo in par} | set(CABLEADO)

    fase1 = [(r, p) for r, p in nuevos + modificados if r not in exclusiones]
    # Los pares criticos van juntos y en el orden del par.
    fase2 = [(relativo, PAQUETE / "app" / relativo) for par in PAR_CRITICO for relativo in par]
    fase3 = [(relativo, PAQUETE / "app" / relativo) for relativo in CABLEADO]

    print(f"FASE1 {len(fase1)}  FASE2 {len(fase2)}  FASE3 {len(fase3)}")

    cliente = ferozo.connect(ROOT / ".ferozo-credentials")
    try:
        for indice, (relativo, origen) in enumerate(fase1, start=1):
            for intento in range(3):
                try:
                    subir(cliente, "/app/" + relativo, origen)
                    break
                except Exception:  # noqa: BLE001
                    cliente.close()
                    cliente = ferozo.connect(ROOT / ".ferozo-credentials")
            if indice % 100 == 0:
                print(f"  FASE1 {indice}/{len(fase1)}", flush=True)

        for relativo, origen in fase2:
            subir(cliente, "/app/" + relativo, origen)
            print(f"  FASE2 {relativo}", flush=True)

        for relativo, origen in fase3:
            subir(cliente, "/app/" + relativo, origen)
            print(f"  FASE3 {relativo}", flush=True)

        # Bundles primero; el manifest al final, para que nunca apunte a un
        # archivo que todavia no esta subido.
        bundles = sorted((PAQUETE / "assets" / "dashboard" / "assets").glob("*"))
        for bundle in bundles:
            subir(cliente, "/assets/dashboard/assets/" + bundle.name, bundle)
        print(f"  FASE4 bundles={len(bundles)}", flush=True)
        subir(
            cliente,
            "/assets/dashboard/.vite/manifest.json",
            PAQUETE / "assets" / "dashboard" / ".vite" / "manifest.json",
        )
        print("  FASE4 manifest", flush=True)
    finally:
        try:
            cliente.quit()
        except Exception:  # noqa: BLE001
            cliente.close()

    print("UPLOAD_OK")
    return 0


if __name__ == "__main__":
    sys.exit(main())
