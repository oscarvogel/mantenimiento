# Bitácora — Porcentajes de liquidación de chofer en TSA (2026-11-24)

## Motivo

Carlos consultó por qué la columna **COMISIÓN** del detalle del viaje 4
(`OIU270`, empresa TSA) mostraba "Sin datos", con la sospecha de que algo se
había cargado mal.

## Diagnóstico (contra la base real de producción)

El viaje de la consulta es el **operativo `viajes_operativos.id = 11`,
número 4, empresa 4 (TSA), sucursal 4 (TSAARG), tractor OIU270**, estado
`EN_CURSO`, salida 11/11/2026, km de salida 1.206.185. Los tramos coinciden
con la imagen: tramo 1 Puerto Rico → Buenos Aires y tramo 2 CHACABUCO →
RIQUEZA BRASIL.

Causa raíz, verificada con una sonda de solo lectura sobre la base
productiva:

1. La comisión se calcula en `GetOperationalTrip::financials()` como
   `flete_liquidacion_ars × porcentaje / 100`
   (`app/Application/Trips/GetOperationalTrip.php:269`). Si falta el porcentaje
   **o** falta el flete de liquidación del tramo, la comisión queda `null` y la
   interfaz muestra "Sin datos".
2. El porcentaje se resuelve en este orden:
   - `viaje_operativo_asignaciones.porcentaje_liquidacion` del viaje, o
   - si está vacío, la fila vigente del maestro
     `viajes_porcentajes_liquidacion_chofer` **a la fecha de salida** del viaje
     (`CodeIgniterOperationalTripReadModel::masterPercentagesForDrivers()`).
3. Las 25 filas del maestro se cargaron en un solo lote el **04/11/2026**
   (`vigente_desde` 2026-11-04, todas 14,00%). **MIGUEL SEVERO (empleado 95)**
   y **ANIBAL MELA (empleado 98)** se dieron de alta el **11/11/2026**, después
   del lote: nunca entraron.
4. El alta de viaje no exige el porcentaje:
   `CreateTripCommand::driverPercentages()` guarda `null` si el campo viene
   vacío, sin validar. Por eso el hueco no se notó al crear el viaje.

Estado previo: 41 viajes con chofer, 39 resolvían porcentaje, y **los 39
resolvían por la asignación del viaje, ninguno por el maestro** (el maestro
solo aplica a viajes que salen desde el 04/11).

## Corrección aplicada en producción

Operación: helper HTTP temporal subido por FTPS a la raíz del release,
llamado con el header `X-Migrate-Token`, y borrado inmediatamente después.
**Dry-run obligatorio antes de cada escritura** (`apply=1`).

| Acción | Detalle |
| --- | --- |
| Viaje 9 (`id=26`, PEDROZO JORGE) | `porcentaje_liquidacion` = 14,00 en su asignación (antes `null`) |
| Viaje 23 (`id=68`, ANIBAL MELA) | `porcentaje_liquidacion` = 14,00 en su asignación (antes `null`) |
| Maestro — ANIBAL MELA (98) | Alta: 14,00%, `vigente_desde` 2026-11-04, `vigente_hasta` NULL, activo |
| Maestro — MIGUEL SEVERO (95) | Alta: 14,00%, `vigente_desde` 2026-11-04, `vigente_hasta` NULL, activo |

Todo en una sola transacción (`transBegin`/`transCommit`, con
`transRollback` ante error). El primer lote de correcciones (2 viajes + alta
de ANIBAL MELA) y el segundo (alta de MIGUEL SEVERO) se aplicaron por
separado, cada uno con su dry-run.

`vigente_desde` = 2026-11-04 para las filas nuevas, igual que las 25
existentes, para que el maestro quede homogéneo y sin huecos.

### Verificación posterior (sonda de solo lectura)

- Maestro: 25 → **27 filas**.
- Viajes con chofer: **42 de 42 con porcentaje resuelto** (antes 40 de 42).
- Choferes reales sin fila en el maestro: **0**.
- Sin solapamiento de periodos: el maestro no admite periodos solapados
  (`CodeIgniterTripMasterRepository::hasOverlappingLiquidationRate`), y por eso
  a PEDROZO JORGE —que ya tiene fila desde el 04/11, posterior a la salida de
  su viaje del 28/10— solo se le fijó el porcentaje en el viaje.

### Estado del viaje 4 (el de la consulta de Carlos)

| Tramo | Facturación | Liquidación | Comisión | Margen |
| --- | --- | --- | --- | --- |
| 1 — Puerto Rico → Buenos Aires | 3.030.000 | 3.030.000 | 424.200 | 2.605.800 |
| 2 — CHACABUCO → RIQUEZA BRASIL | 2.030.000 | 2.065.000 | 289.100 | 1.740.900 |

## Lo que NO se tocó, y por qué

### 1. 22 tramos en 18 viajes (todos `EN_CURSO`) sin flete de liquidación

Tienen flete de facturación cargado pero `flete_liquidacion_ars` en `NULL`.
Mientras siga así, su comisión y margen quedan en "Sin datos" **aunque el
chofer tenga porcentaje**: la comisión se calcula sobre la liquidación.

No se completaron por dato, y la medición lo confirma: en los **40 tramos con
ambos fletes cargados, la liquidación coincide con la facturación en solo 7
(17,5%) y difiere en 33**, casi siempre a la baja (hasta −2.850.000). La
liquidación es el monto **pactado con el chofer**, no un calco de la
facturación; completarla por convención habría calculado **18.431.598 ARS**
de comisión sobre base falsa.

Decisión del 24/11/2026: **los carga la operación en la app**, que ya nombra
los tramos pendientes y bloquea el cierre del viaje hasta que estén
completos (*"Completá los fletes de facturación y liquidación de los tramos N
para calcular facturación, comisión y margen"*).

### 2. Los 3 gastos del viaje 4 (71.000 ARS, pagados por CAJA)

Siguen con `tramo_id = NULL`: aparecen en "Costos sin asignar" y no se
descuentan del margen. Imputar un gasto a un tramo es decisión operativa, no
un dato mecánico.

## Pendiente (PR a parte, no en este cambio)

1. **Exigir el porcentaje en el alta de viaje** o, como mínimo, avisar cuando
   el chofer no tiene fila en el maestro. Hoy `CreateTripCommand` acepta el
   `null` en silencio: es lo que permitió que el hueco apareciera dos veces.
2. Aviso al operador cuando el % no resuelve por maestro y el viaje sale de
   una fecha sin cobertura.

## Herramientas (este PR)

| Archivo | Qué hace |
| --- | --- |
| `scripts/sonda-viajes-operativos.php` | Sonda de **solo lectura**: viajes, tramos, gastos, maestro, choferes sin fila y tramos sin liquidación |
| `scripts/run-sonda-viajes.py` | Sube la sonda por FTPS, la llama con `X-Migrate-Token` y la borra |
| `scripts/correccion-porcentajes-chofer-produccion.php` | Corrección de porcentajes y del maestro. **Dry-run por defecto**; escribe solo con `apply=1` |
| `scripts/run-correccion-porcentajes.py` | Sube el corrector por FTPS, lo ejecuta y lo borra |

Los cuatro son herramientas locales: `scripts/deploy-ferozo.py` clasifica
`scripts/` como "script/herramienta local" y **no** lo publica en el runtime
productivo.

## Procedimiento reproducible

```bash
# Diagnóstico (solo lectura)
python scripts/run-sonda-viajes.py --patente OIU270 --numero 4

# Corrección: primero dry-run, después aplicar
python scripts/run-correccion-porcentajes.py
python scripts/run-correccion-porcentajes.py --apply
```

En ambos casos el archivo se sube a la raíz del release, se llama con el
header `X-Migrate-Token` (valor de `MIGRATE_TOKEN` en el `.env` de producción)
y se borra por FTPS al terminar. Verificado: la raíz del release quedó sin
scripts de operación al cierre de esta intervención.
