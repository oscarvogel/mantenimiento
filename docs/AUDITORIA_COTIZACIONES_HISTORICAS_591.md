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

### Tramo facturacion / TRAMO_FACTURACION #140
- Estado viaje: EN_CURSO | Estado registro: ACTIVO
- Fecha: 2026-11-17 10:14:07
- Tipo registro: None
- Importe original: 5040 USD
- Cotización registrada: 1500
- Cotización vigente catálogo (referencia, no aplicada): 1500
- ARS registrado: 7560000
- ARS esperado por original×cotización registrada: 7560000
- Diferencia ARS: 0
- Clasificación: OK (OK)
- Observaciones: None
- Evidencia confirmación explícita tasa==1: None

### Tramo liquidacion / TRAMO_LIQUIDACION #140
- Estado viaje: EN_CURSO | Estado registro: ACTIVO
- Fecha: 2026-11-17 10:14:07
- Tipo registro: None
- Importe original: 5800000 ARS
- Cotización registrada: 1
- Cotización vigente catálogo (referencia, no aplicada): None
- ARS registrado: 5800000
- ARS esperado por original×cotización registrada: 5800000
- Diferencia ARS: 0
- Clasificación: OK (OK)
- Observaciones: None
- Evidencia confirmación explícita tasa==1: None

## Conclusión viaje 28

En la extracción actual, el movimiento de caja BRL 300 tiene cotización registrada 290 y ARS registrado 87000; no aparece como tasa==1. El caso reportado originalmente en #591 (BRL 300, cotización 1, ARS 300) no se observa en el estado actual de producción auditado. No se realizaron correcciones automáticas.

## Artefactos

- CSV completo (datos reales, fuera del repo): `C:\Users\Ventas\auditoria-cotizaciones-viajes-591.csv`
- CSV viaje 28 (datos reales, fuera del repo): `C:\Users\Ventas\auditoria-cotizaciones-viajes-591-trip28.csv`
- Resumen MD: `docs/AUDITORIA_COTIZACIONES_HISTORICAS_591.md`
- Procedimiento: `docs/PROCEDIMIENTO_REGULARIZACION_COTIZACIONES_591.md`