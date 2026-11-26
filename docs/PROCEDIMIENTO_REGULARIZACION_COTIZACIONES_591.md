# Procedimiento de regularización de cotizaciones - Issue #591

## Alcance

Regularización administrativa de movimientos monetarios con cotización sospechosa o confirmadamente errónea. Aplica a caja, combustible, gastos, otros haberes y tramos donde corresponda. No aplica corrección automática.

## Principios

- No modificar registros finalizados/cerrados sin autorización explícita.
- No recalcular históricos con cotización actual del catálogo.
- Toda corrección requiere evidencia, usuario autorizado, motivo y auditoría antes/después.
- La tasa extranjera == 1 es sospechosa por defecto; no se corrige automáticamente sin evidencia.
- Diferencias >10% contra catálogo actual son revisión administrativa, no error confirmado automático.

## Flujo seguro

1. **Identificación**: ejecutar `scripts/run-auditoria-cotizaciones-viajes-591.py` con salida fuera del repo. Revisar CSV y clasificaciones.
2. **Evidencia**: para cada movimiento candidato reunir comprobante, captura, conversación, fuente de cotización y fecha valor. Determinar si hubo confirmación explícita de tasa==1.
3. **Clasificación administrativa**: ERROR_CONFIRMADO solo con evidencia suficiente; SOSPECHOSO/REVISION_ADMINISTRATIVA requieren validación humana.
4. **Autorización**: obtener aprobación explícita antes de tocar producción. Para viajes finalizados/cerrados, autorización específica.
5. **Preparación**: generar script de corrección puntual con IDs exactos, valores antes/después, transacción, rollback, auditoría. No usar UPDATE masivo sin WHERE por IDs revisados.
6. **Ejecución controlada**: backup/alcance, ejecutar en ventana controlada, verificar filas afectadas = esperadas, registrar auditoría con motivo, usuario, fecha, antes/después, moneda, importe, cotización, ARS.
7. **Post-verificación**: reejecutar auditoría #591, validar rendición/liquidación/caja/PDF afectados, adjuntar evidencia.
8. **Trazabilidad**: documentar en issue/bitácora la autorización, evidencia, script, resultado y verificación.

## Caso viaje 28

- Estado actual auditado: movimiento BRL 300 con cotización 290, ARS 87000; no requiere corrección por tasa==1 en el estado actual observado.
- Si apareciera evidencia histórica de tasa==1 errónea, seguir el flujo anterior antes de cualquier cambio.

## Rollback

- Los scripts de corrección deben correr en transacción y hacer rollback ante cualquier divergencia.
- Mantener backup lógico de filas afectadas (antes/después JSON) fuera del repo.
- Si se detecta impacto no previsto, revertir con script inverso generado desde el backup lógico antes de cierre de ventana.