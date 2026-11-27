#!/usr/bin/env python3
"""Genera CSV y resumen Markdown para auditoría #591 desde JSON read-only.

No accede a producción. Lee el JSON generado por run-auditoria-cotizaciones-viajes-591.py.
"""
from __future__ import annotations

import argparse
import csv
import json
import pathlib
from collections import Counter, defaultdict

ROOT = pathlib.Path(__file__).resolve().parents[1]


def main() -> int:
    p = argparse.ArgumentParser()
    p.add_argument("--input", required=True, help="JSON de auditoría #591")
    p.add_argument("--csv", required=True, help="Ruta CSV salida (fuera del repo si datos reales)")
    p.add_argument("--summary-md", required=True, help="Ruta resumen MD salida")
    p.add_argument("--trip28-md", required=True, help="Ruta diagnóstico viaje 28 salida")
    args = p.parse_args()

    data = json.loads(pathlib.Path(args.input).read_text(encoding="utf-8"))
    obs = data.get("observaciones", [])
    resumen = data.get("resumen", {})

    csv_path = pathlib.Path(args.csv)
    csv_path.parent.mkdir(parents=True, exist_ok=True)
    cols = [
        "viaje", "estado_viaje", "estado_registro", "empresa", "sucursal_id", "tipo_movimiento", "tipo_registro", "circuito",
        "movimiento_id", "tabla", "fecha", "camion", "chofer", "importe_original", "moneda", "cotizacion_registrada",
        "cotizacion_vigente_catalogo", "importe_ars_registrado", "importe_ars_esperado", "diferencia_ars",
        "clasificacion", "severidad", "motivos", "confirmacion_explicita_evidencia", "anulado_at", "deleted_at",
        "observaciones", "comprobante", "pagado_por", "updated_at", "created_at",
    ]
    with csv_path.open("w", encoding="utf-8-sig", newline="") as f:
        w = csv.DictWriter(f, fieldnames=cols, extrasaction="ignore")
        w.writeheader()
        for r in obs:
            row = dict(r)
            row["motivos"] = " | ".join(r.get("motivos") or [])
            row["confirmacion_explicita_evidencia"] = json.dumps(r.get("confirmacion_explicita_evidencia"), ensure_ascii=False) if r.get("confirmacion_explicita_evidencia") else ""
            w.writerow(row)

    by_company = Counter(r.get("empresa") for r in obs)
    by_state = Counter(r.get("estado_viaje") for r in obs)
    by_circuit = Counter(r.get("circuito") for r in obs)
    by_class = Counter()
    for r in obs:
        for c in str(r.get("clasificacion", "")).split("|"):
            if c:
                by_class[c] += 1
    by_sev = Counter(r.get("severidad") for r in obs)

    trip28 = [r for r in obs if str(r.get("viaje")) == "28" or str(r.get("viaje_id")) == "28"]
    # also include all movements from trip 28 from raw data? JSON only has observaciones; for trip 28 diagnosis we need all movements. Add note.

    summary = pathlib.Path(args.summary_md)
    summary.parent.mkdir(parents=True, exist_ok=True)
    lines = []
    lines += ["# Resumen ejecutivo auditoría cotizaciones #591", ""]
    lines += [f"- Generado en: {data.get('generado_en')}", f"- Base: {data.get('base')}", f"- Transacción solo lectura: {data.get('transaccion_solo_lectura')}", f"- Nota transacción: {data.get('nota_transaccion')}", ""]
    lines += ["## Totales", ""]
    for k in ["total_movimientos_auditados","total_viajes_auditados","total_sospechosos","total_error_confirmado","total_revision_administrativa","total_ok","impacto_estimado_sospechoso_ars"]:
        lines.append(f"- {k}: {resumen.get(k)}")
    lines += ["", "## Distribución por severidad", ""]
    for k,v in by_sev.most_common():
        lines.append(f"- {k}: {v}")
    lines += ["", "## Distribución por clasificación", ""]
    for k,v in by_class.most_common():
        lines.append(f"- {k}: {v}")
    lines += ["", "## Distribución por estado de viaje", ""]
    for k,v in by_state.most_common():
        lines.append(f"- {k}: {v}")
    lines += ["", "## Distribución por circuito", ""]
    for k,v in by_circuit.most_common():
        lines.append(f"- {k}: {v}")
    lines += ["", "## Distribución por empresa", ""]
    for k,v in by_company.most_common():
        lines.append(f"- {k}: {v}")
    lines += ["", "## Top viajes afectados por impacto estimado sospechoso", ""]
    impact_by_trip = defaultdict(float)
    for r in obs:
        if r.get("severidad") == "SOSPECHOSO":
            amt = r.get("diferencia_ars")
            # for rate==1 suspicious, estimate against catalog if present
            if str(r.get("moneda","")) != "ARS" and abs(float(r.get("cotizacion_registrada") or 0)-1.0) < 0.0000005 and r.get("cotizacion_vigente_catalogo") and r.get("importe_original") is not None:
                est = abs(float(r["importe_original"]) * float(r["cotizacion_vigente_catalogo"]) - float(r.get("importe_ars_registrado") or 0))
                impact_by_trip[str(r.get("viaje"))] += est
            elif amt is not None:
                impact_by_trip[str(r.get("viaje"))] += abs(float(amt))
    for k,v in sorted(impact_by_trip.items(), key=lambda kv: kv[1], reverse=True)[:30]:
        lines.append(f"- Viaje {k}: ARS {v:,.2f}")
    lines += ["", "## Observaciones", "", "- Auditoría read-only. No se modificaron registros.", "- Diferencias >10% contra catálogo actual se clasifican como revisión administrativa, no error automático.", "- Tasa extranjera == 1 sin evidencia explícita se clasifica sospechosa, no corregida automáticamente.", ""]
    summary.write_text("\n".join(lines), encoding="utf-8")

    t28 = pathlib.Path(args.trip28_md)
    t28.parent.mkdir(parents=True, exist_ok=True)
    t28_lines = ["# Diagnóstico Viaje #28 - Issue #591", "", "## Movimientos observados en auditoría (solo clasificados)", ""]
    if not trip28:
        t28_lines.append("- No se encontraron movimientos clasificados como observación para viaje 28 en esta extracción. Verificar con extracción completa del viaje.")
    for r in trip28:
        t28_lines += [
            f"### {r.get('circuito')} #{r.get('movimiento_id')} ({r.get('tabla')})",
            f"- Fecha: {r.get('fecha')}",
            f"- Tipo: {r.get('tipo_registro')}",
            f"- Importe original: {r.get('importe_original')} {r.get('moneda')}",
            f"- Cotización registrada: {r.get('cotizacion_registrada')}",
            f"- ARS registrado: {r.get('importe_ars_registrado')}",
            f"- ARS esperado por original×cotización: {r.get('importe_ars_esperado')}",
            f"- Diferencia ARS: {r.get('diferencia_ars')}",
            f"- Clasificación: {r.get('clasificacion')} / {r.get('severidad')}",
            f"- Motivos: {' | '.join(r.get('motivos') or [])}",
            f"- Observaciones: {r.get('observaciones')}",
            f"- Evidencia confirmación explícita: {r.get('confirmacion_explicita_evidencia')}",
            "",
        ]
    t28_lines += ["## Nota", "", "- Este archivo se genera desde la extracción read-only. La regularización requiere revisión administrativa y no debe aplicarse automáticamente.", ""]
    t28.write_text("\n".join(t28_lines), encoding="utf-8")

    print(f"CSV {csv_path}")
    print(f"SUMMARY {summary}")
    print(f"TRIP28 {t28}")
    print(f"OBS {len(obs)}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
