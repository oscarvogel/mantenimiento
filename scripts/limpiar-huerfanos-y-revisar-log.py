#!/usr/bin/env python3
"""Limpia los bundles huerfanos del build y revisa el log de produccion.

Los huerfanos son los que referenciaba el manifest viejo y el nuevo no. No
toca el residuo de builds anteriores, que es de otro deploy.
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

HUERFANOS = [
    "assets/FleetTelemetryMap-C6tIc_tQ.js",
    "assets/admin-CU2DQ1Mp.js",
    "assets/chatbot-MAKWnyyI.js",
    "assets/main-Cf6vvpeB.css",
    "assets/main-DpqSueYJ.js",
    "assets/profile-BAFLtpcM.js",
    "assets/profile-C6Wm1qkw.js",
    "assets/profile-pjcg2II2.js",
    "assets/reports-BXe2RElM.js",
    "assets/vendor-CHJfqGAg.js",
]


def main() -> int:
    cliente = ferozo.connect(ROOT / ".ferozo-credentials")
    try:
        for nombre in HUERFANOS:
            remoto = "/assets/dashboard/" + nombre
            try:
                cliente.delete(remoto)
                print(f"BORRADO {nombre}")
            except Exception as error:  # noqa: BLE001
                print(f"NO_SE_PUDO_BORRAR {nombre} ({type(error).__name__})")

        print("--- LOG DE PRODUCCION (errores) ---")
        buffer = io.BytesIO()
        try:
            cliente.retrbinary("RETR /writable/logs/log-2026-10-25.log", buffer.write)
        except Exception as error:  # noqa: BLE001
            print(f"NO_SE_PUDO_LEER_EL_LOG ({type(error).__name__})")
            return 0
        lineas = buffer.getvalue().decode("utf-8", errors="replace").splitlines()
        errores = [linea for linea in lineas if "CRITICAL" in linea or "ERROR" in linea]
        print(f"LINEAS {len(lineas)}  ERRORES {len(errores)}")
        for linea in errores[-40:]:
            print("  " + linea[:300])
    finally:
        try:
            cliente.quit()
        except Exception:  # noqa: BLE001
            cliente.close()
    return 0


if __name__ == "__main__":
    sys.exit(main())
