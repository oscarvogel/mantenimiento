# Liquidación del chofer: vista segura y dos modalidades de acuerdo (#533)

**Fecha:** 17 de noviembre de 2026
**Contexto:** Viajes (bounded context 12), modelo operativo.
**Pedido:** issue #533 y su ampliación del 17/11 (audio 3 de Carlos, Administración).
**Rama:** `feat/viajes-liquidacion-chofer-533`.

---

## 1. Qué existía

| Pieza | Estado antes de #533 |
|---|---|
| `TripLeg` con `invoice` y `liquidation` (`TripLegAmount`) | Separaba facturación y liquidación, cada una con su moneda y cotización (PR #523). |
| `flete_liquidacion` / `flete_liquidacion_ars` | Un **importe global**: era, en los hechos, la modalidad «monto acordado». |
| `toneladas` del tramo | Dato operativo del tramo; no intervenía en la liquidación. |
| `GetOperationalTrip` | Calcula comisión por tramo, `TripSettlement` y margen. Única fuente del cálculo. |
| `viajes_cotizaciones` (#508) y moneda por flete (#507/#523) | Cotización vigente por empresa para precargar; cada importe guarda su snapshot. |
| PDF `liquidacion-chofer.pdf` | Recibía el **detalle administrativo completo** (fletes de facturación, margen, cliente) y elegía qué imprimir. Importes con `$`. Colgaba de `viajes.ver`. |
| Auditoría | Combustible, gastos, caja, haberes y diario de bordo auditados; la edición de tramos solo dejaba `updated_by`. |

## 2. Qué se reutilizó

- El cálculo de comisión, haberes, gastos, anticipos y saldo de `GetOperationalTrip` + `TripSettlement`. No hay un segundo cálculo.
- `TripLegAmount` (moneda y cotización de liquidación) y las cotizaciones de #508: sin tablas ni reglas nuevas de monedas.
- `flete_liquidacion*`: siguen siendo el importe que leen comisión, cierre (`ordersWithMissingAmounts`) y PDF.
- La tabla `viaje_operativo_movimientos_auditoria`, ahora con `movimiento_tipo = 'TRAMO'`.
- El PDF existente (`DriverSettlementPdfGenerator`): se adaptó, no se creó otro.

## 3. Qué cambió

### Dominio
- `TripLegLiquidationBasis` (nuevo): `POR_TONELADA` (toneladas × precio) o `MONTO_ACORDADO`. Nunca deduce toneladas ni precio inverso.
- `TripLeg` acepta la base y exige que coincida con el flete de liquidación. Sin base, el tramo es un monto acordado, como siempre.

### Aplicación
- `TripLegLiquidationBasisInput` lee `modo_liquidacion`, `toneladas_liquidacion` y `precio_unitario_liquidacion`. Sin modo (integraciones viejas) se interpreta monto acordado.
- `DriverSettlementStatement`: proyección **por lista blanca** del detalle. Sin facturación, cliente, cotización de facturación, costos, margen ni observaciones; solo los haberes del chofer responsable; cotización solo si la moneda no es ARS.
- `GetDriverSettlement` (permiso `viajes.liquidacion_chofer.ver` + scope de `GetOperationalTrip`).
- `GenerateDriverSettlementPdf` imprime la misma proyección. Antes del cierre sale como **borrador** y sin firmas.

### Infraestructura y presentación
- Ruta `GET viajes/operativos/{id}/liquidacion-chofer` (vista) y el PDF existente, ambos con `permission:viajes.liquidacion_chofer.ver`. El PDF se abre en línea para imprimir.
- Repositorio de tramos: persiste el acuerdo y audita cada edición con antes/después.
- Vue: botón **«Ver liquidación del chofer»** en el detalle; página `TripDriverSettlementPage`; componente `TripLegLiquidationBasisFields` en alta, agregar y editar tramo, con la modalidad elegida de forma explícita.
- Importes del PDF con código ISO (`ARS 1.350.000,00`), nunca `$`.

## 4. Migraciones

| Migración | Qué hace | Reversión |
|---|---|---|
| `2026-11-17-120000_AddTripLegLiquidationBasis` | Agrega `modo_liquidacion`, `toneladas_liquidacion DECIMAL(14,3)`, `precio_unitario_liquidacion DECIMAL(18,4)`. Backfill: tramos existentes → `MONTO_ACORDADO`. No toca importes. Idempotente. | `down()` se niega si hay tramos `POR_TONELADA`. |
| `2026-11-17-120100_AddDriverSettlementViewPermission` | Crea `viajes.liquidacion_chofer.ver` y lo da a Administrador y a los roles con `viajes.operar` o `viajes.economico.ver`. Idempotente. | Borra el permiso y sus relaciones. |

## 5. Verificación

- PHPUnit: 1884 pruebas, 0 fallos, 45 omitidas. Vitest: 490 pruebas, 0 fallos. `npm run build` con `ENCODING_QA=OK`.
- MariaDB 10.11 descartable (no es `fasa_189`): cadena completa de migraciones, backfill sobre un viaje cerrado (USD 5.000 facturado, ARS 5.100.000 liquidado) sin cambio de importes, `down()` protegido, permiso en los tres roles, carga por toneladas 30 × 45.000 = 1.350.000 con auditoría, proyección y PDF sin facturación, rechazo sin permiso y entre empresas.
- La cadena limpia sigue rota por `2026-11-06-100000_AddPrincipalFlagToLocalities` (preexistente, ver bitácora del 12/11); se salteó igual que entonces.
- Capturas en `docs/screenshots/533/` (renderizadas desde el bundle con payloads reales, sin servidor PHP).

## 6. Pendientes y riesgos

1. **Validación en staging `fasa_189`** y aceptación visual con sesión real: no se hizo desde este entorno.
2. El rol **Consulta** deja de poder bajar el PDF de liquidación (antes bastaba `viajes.ver`). Se puede asignar el permiso nuevo si corresponde.
3. Viajes cerrados sin porcentaje propio en la asignación usan el maestro vigente a la fecha del viaje (comportamiento existente). Si se edita un maestro histórico en lugar de crear una vigencia nueva, cambia la liquidación mostrada.
4. Los formularios piden la modalidad de forma explícita; integraciones que posteen sin `modo_liquidacion` siguen como monto acordado.
5. Cuenta corriente del cliente (#530) y circuito de facturación: sin cambios.
