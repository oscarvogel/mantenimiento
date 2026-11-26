# Auditoría histórica de cotizaciones - Issue #591

- Fecha de generación: 2026-11-26 15:44:53
- Base auditada: a0110632_mant
- Modo: SOLO LECTURA (`START TRANSACTION READ ONLY` cuando soportado: True)
- Nota transacción: None
- Herramienta: `scripts/auditoria-cotizaciones-viajes-591.php` ejecutada vía `scripts/run-auditoria-cotizaciones-viajes-591.py` (helper efímero FTPS/HTTPS, borrado verificado).
- Sin modificaciones de datos. Sin recalculo histórico con cotización actual del catálogo.

## Resumen ejecutivo

- **total_movimientos_auditados**: 229
- **total_viajes_auditados**: 42
- **total_sospechosos**: 0
- **total_error_confirmado**: 0
- **total_revision_administrativa**: 0
- **total_ok**: 229
- **impacto_estimado_sospechoso_ars**: 0

## Distribución por clasificación

- OK: 229

## Distribución por estado de viaje

- EN_CURSO: 202
- FINALIZADO: 25
- CANCELADO: 2

## Distribución por circuito

- Caja del viaje: 36
- Combustible: 8
- Gastos del viaje: 21
- Otros haberes: 56
- Tramo facturacion: 64
- Tramo liquidacion: 44

## Observaciones clasificadas

No se detectaron movimientos sospechosos/confirmados/revisión administrativa bajo las reglas de la #591 en la extracción ejecutada.

## Diagnóstico Viaje #28 (TSA)

El viaje 28 fue auditado en modo read-only. Movimientos extraídos (todos, incluidos OK):

### Caja del viaje / CAJA #92
- Estado viaje: EN_CURSO | Estado registro: ACTIVO
- Fecha: 2026-11-25 17:45:00
- Tipo registro: ANTICIPO
- Importe original: 150000 ARS
- Cotización registrada: 1
- Cotización vigente catálogo (referencia, no aplicada): None
- ARS registrado: 150000
- ARS esperado por original×cotización registrada: 150000
- Diferencia ARS: 0
- Clasificación: OK (OK)
- Observaciones: mercosur
- Evidencia confirmación explícita tasa==1: None

### Caja del viaje / CAJA #95
- Estado viaje: EN_CURSO | Estado registro: ACTIVO
- Fecha: 2026-11-14 17:48:00
- Tipo registro: ANTICIPO
- Importe original: 300 BRL
- Cotización registrada: 290
- Cotización vigente catálogo (referencia, no aplicada): 290
- ARS registrado: 87000
- ARS esperado por original×cotización registrada: 87000
- Diferencia ARS: 0
- Clasificación: OK (OK)
- Observaciones: PIX [REDACTADO]
- Evidencia confirmación explícita tasa==1: None

## 5.1 Trazabilidad histórica caja #95 (viaje 28 / viaje operativo 83)

Investigación read-only ejecutada el 2026-11-26 contra producción (`a0110632_mant`) con script efímero FTPS+HTTPS, token efímero, `START TRANSACTION READ ONLY`, borrado remoto verificado y HTTP 404 posterior.

Fuente: `viaje_operativo_movimientos_auditoria` (movimiento_tipo=CAJA, movimiento_id=95, viaje_id=83).

| Campo | Valor |
|---|---|
| Auditoría id | 275 |
| Acción | EDICION |
| Fecha auditoría | 2026-11-26 11:44:57 |
| Usuario auditoría | 7 - Mariela Pawluk (mariela@tsa.com.ar, activo) |
| Movimiento creado por | 19 - Kunrath Camila (ventas@webtsa.com.ar, activo), 2026-11-25 17:49:04 |
| Última actualización movimiento | 2026-11-26 11:44:57 por usuario 7 |

### Antes / Después (evidencia JSON auditoría)

| Campo | Antes | Después |
|---|---:|---:|
| importe | 300.0000 BRL | 300.0000 BRL |
| moneda | BRL | BRL |
| cotizacion | 1.000000 | 290.000000 |
| importe_ars | 300.0000 | 87000.0000 |
| observaciones | PIX [REDACTADO] | PIX [REDACTADO] |

### Conclusión de trazabilidad

- **TRAZABILIDAD_HISTORICA_DISPONIBLE** para el cambio monetario observado en auditoría #275.
- El estado reportado originalmente en #591 (BRL 300, cotización 1, ARS 300) **sí existe como estado anterior** en la auditoría del movimiento #95; el estado actual en producción es BRL 300, cotización 290, ARS 87000.
- El cambio registrado modifica cotización e importe ARS; no modifica importe original, moneda ni observaciones. No hay `motivo_cambio` en `datos_nuevos` de esta fila histórica (la fila no lo contiene).
- Impacto económico del cambio auditado: +86.700 ARS en caja del viaje (300 × 290 - 300 × 1). Es diferencia entre estado anterior y actual del mismo movimiento; no implica corrección automática adicional.
- Rendición/liquidación que consumen `importe_ars` histórico deben reflejar 87.000 ARS para este movimiento en el estado actual. No se recalcula ni modifica nada en producción.

## Conclusión viaje 28

En la extracción actual, el movimiento de caja BRL 300 tiene cotización registrada 290 y ARS registrado 87000; no aparece como tasa==1. El caso originalmente reportado en #591 (BRL 300, cotización 1, ARS 300) quedó explicado por la auditoría #275: existió como estado anterior y fue corregido a cotización 290 / ARS 87.000 por usuario 7 (Mariela Pawluk) el 2026-11-26 11:44:57. No se realizaron correcciones automáticas.

## Artefactos

- CSV completo (datos reales, fuera del repo): `C:\Users\Ventas\auditoria-cotizaciones-viajes-591.csv`
- CSV viaje 28 (datos reales, fuera del repo): `C:\Users\Ventas\auditoria-cotizaciones-viajes-591-trip28.csv`
- Resumen MD: `docs/AUDITORIA_COTIZACIONES_HISTORICAS_591.md`
- Procedimiento: `docs/PROCEDIMIENTO_REGULARIZACION_COTIZACIONES_591.md`