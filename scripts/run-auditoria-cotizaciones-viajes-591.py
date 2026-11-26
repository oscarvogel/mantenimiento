#!/usr/bin/env python3
"""Ejecuta la auditoría histórica read-only #591 en Ferozo con helper efímero.

- Genera nombre aleatorio y token efímero.
- Sube script por FTPS, ejecuta por HTTPS con X-Audit-Token.
- Guarda JSON fuera del repo (o en tmp local si se indica explícitamente).
- Borra el script remoto en finally y verifica ausencia.
"""
from __future__ import annotations

import argparse
import importlib.util
import json
import pathlib
import secrets
import sys
import urllib.error
import urllib.request

ROOT = pathlib.Path(__file__).resolve().parents[1]
PLANTILLA = ROOT / "scripts" / "auditoria-cotizaciones-viajes-591.php"
URL_BASE = "https://vogelconsultoria.com.ar/mantenimiento/"
DEFAULT_SALIDA = pathlib.Path.home() / "tmp" / "auditoria-cotizaciones-viajes-591.json"

spec = importlib.util.spec_from_file_location("ferozo_ftps", ROOT / "scripts" / "ferozo-ftps.py")
ferozo = importlib.util.module_from_spec(spec)
sys.modules["ferozo_ftps"] = ferozo
spec.loader.exec_module(ferozo)


def peticion(url: str, token: str = "") -> tuple[int, bytes]:
    cabeceras = {"X-Audit-Token": token} if token else {}
    req = urllib.request.Request(url, headers=cabeceras)
    try:
        with urllib.request.urlopen(req, timeout=600) as respuesta:
            return respuesta.status, respuesta.read()
    except urllib.error.HTTPError as error:
        return error.code, error.read()


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--salida", default=str(DEFAULT_SALIDA), help="Ruta local para guardar JSON (preferir fuera del repo)")
    args = parser.parse_args()
    salida = pathlib.Path(args.salida).resolve()

    token = secrets.token_urlsafe(48)
    nombre = "aud-cotiz-591-" + secrets.token_hex(12) + ".php"
    url = URL_BASE + nombre

    plantilla = PLANTILLA.read_text(encoding="utf-8")
    if "__AUDIT_TOKEN__" not in plantilla:
        print("ERROR: plantilla sin marcador de token")
        return 1
    contenido = plantilla.replace("__AUDIT_TOKEN__", token).encode("utf-8")

    cliente = ferozo.connect(ROOT / ".ferozo-credentials")
    borrado = False
    try:
        from io import BytesIO

        cliente.storbinary("STOR /" + nombre, BytesIO(contenido), blocksize=256 * 1024)
        print("SCRIPT_SUBIDO " + nombre)

        codigo, crudo = peticion(url, token)
        cuerpo = crudo.decode("utf-8", errors="replace")
        print("HTTP_AUDITORIA " + str(codigo))
        if codigo != 200 or not cuerpo.lstrip().startswith("{"):
            print("ERROR_RESPUESTA_NO_JSON")
            print(cuerpo[:500])
            return 1

        informe = json.loads(cuerpo)
        if not informe.get("ok"):
            print("ERROR_INFORME_NO_OK")
            return 1

        try:
            salida.parent.mkdir(parents=True, exist_ok=True)
        except FileExistsError:
            pass
        salida.write_text(cuerpo, encoding="utf-8")
        print("INFORME_LOCAL " + str(salida))
        resumen = informe.get("resumen", {})
        print("RESUMEN " + json.dumps({
            "total_movimientos_auditados": resumen.get("total_movimientos_auditados"),
            "total_viajes_auditados": resumen.get("total_viajes_auditados"),
            "total_sospechosos": resumen.get("total_sospechosos"),
            "total_error_confirmado": resumen.get("total_error_confirmado"),
            "total_revision_administrativa": resumen.get("total_revision_administrativa"),
            "total_ok": resumen.get("total_ok"),
            "impacto_estimado_sospechoso_ars": resumen.get("impacto_estimado_sospechoso_ars"),
        }, ensure_ascii=False, sort_keys=True))
        return 0
    finally:
        try:
            cliente.delete("/" + nombre)
            borrado = True
            print("BORRADO_REMOTO " + nombre)
        except Exception as error:  # noqa: BLE001
            print("NO_SE_PUDO_BORRAR " + type(error).__name__)
        try:
            remotos = [n for n, _ in ferozo.entries(cliente, "/")]
            print("AUSENTE_EN_FTPS " + str(nombre not in remotos))
        except Exception:  # noqa: BLE001
            print("AUSENTE_EN_FTPS no_verificable")
        try:
            cliente.quit()
        except Exception:  # noqa: BLE001
            cliente.close()
        if borrado:
            codigo2, crudo2 = peticion(url)
            texto2 = crudo2.decode("utf-8", errors="replace")
            es_desafio = len(crudo2) > 8000 and "{" not in texto2[:200]
            print("VERIFICACION_HTTP " + json.dumps({
                "codigo": codigo2,
                "es_desafio_antibot": es_desafio,
                "es_404": codigo2 == 404 and not es_desafio,
            }, ensure_ascii=False, sort_keys=True))


if __name__ == "__main__":
    sys.exit(main())
