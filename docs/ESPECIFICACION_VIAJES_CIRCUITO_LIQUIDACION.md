# Especificación: circuito de viajes, tramos, cuenta corriente y liquidación

**Estado:** Fase 1 implementada y verificada. Especificación vigente del circuito.
**Fecha:** 24/10/2026 (revisión de estado sobre la versión del 21/10/2026).
**Base:** descripción de Carlos Secre TSA del 21/10/2026, pantallas del sistema legacy y estado actual del módulo Viajes en la rama `codex/dashboard-detalle-viajes` (commit `1802960f`).
**Objetivo:** documentar el circuito operativo y económico completo, compararlo con lo ya implementado y dejar explícito qué hay que cambiar antes de programar.

> **Revisión del 24/10/2026.** La sección 4 estaba desactualizada: el 21/10 la Fase 1 todavía no estaba
> programada, y desde entonces se implementaron los diez puntos que figuraban como faltantes. La sección 4
> refleja el estado real y la 18 registra la revisión. Sigue pendiente la Fase 2 (aprobación y pago de
> liquidaciones) y las respuestas del cliente de la sección 16.

---

## 1. Resumen ejecutivo

El viaje debe funcionar como un ciclo económico completo, no solo como una planificación de recorrido:

```text
Iniciar viaje aprobado
  -> cargar fecha y km reales de salida, equipo y chofer
  -> el viaje queda En curso al guardar la ficha
  -> cargar uno o más tramos con dos importes: facturación y liquidación
  -> cargar combustible y gastos (opcionalmente imputados a un tramo)
  -> cargar cuenta corriente del chofer y otros haberes
  -> cerrar el viaje con km de regreso
  -> generar lectura del equipo
  -> calcular liquidación del chofer
  -> calcular margen del viaje y de cada tramo
```

La decisión central es separar dos cosas que el legacy mezclaba:

1. **Resultado del viaje:** facturado menos combustible, gastos, comisión del chofer y otros haberes del viaje.
2. **Liquidación del chofer:** comisión más otros haberes más gastos pagados por el chofer, menos adelantos y devoluciones.

Los adelantos **no** son un costo del viaje: son movimientos de cuenta corriente. Afectan lo que se le paga al chofer, no el margen operativo del viaje.

---

## 2. Lenguaje ubicuo

| Término | Significado |
|---|---|
| **Viaje** | Ciclo operativo completo desde la apertura hasta el regreso y cierre. Puede contener varios tramos. |
| **Tramo** | Recorrido comercial entre un origen y un destino. Es la unidad de facturación, de liquidación y de margen. |
| **Flete de facturación** | Importe que se cobra al cliente por el tramo. Es el ingreso del viaje. |
| **Flete de liquidación** | Base sobre la que se calcula el porcentaje del chofer. No tiene que coincidir con el flete de facturación. |
| **Comisión del chofer** | `flete de liquidación del tramo × porcentaje del chofer`. |
| **Combustible** | Cargas de combustible del viaje. Se usa para consumo y como costo. |
| **Gasto del viaje** | Peajes, gomería, aduana, estacionamiento, viáticos y otros gastos. Puede estar pagado por la empresa, por el chofer o con la caja del viaje. |
| **Cuenta corriente del chofer** | Libro de movimientos entre la empresa y el chofer: adelantos, devoluciones, gastos a favor, otros haberes y ajustes. |
| **Otros haberes** | Haberes adicionales del viaje, por ejemplo viáticos o adicionales. |
| **Liquidación del chofer** | Total a pagar o cobrar al chofer al cerrar el viaje. |
| **Margen del tramo** | Flete de facturación del tramo menos costos asignados al tramo menos comisión del tramo. |
| **Margen del viaje** | Flete de facturación total menos todos los costos del viaje menos comisiones y otros haberes. |
| **Costo sin asignar** | Gasto o combustible cargado a nivel viaje, sin tramo. Suma al margen del viaje pero no al de un tramo. |
| **SIN DATOS SUFICIENTES** | Estado visible cuando falta un dato mínimo para calcular un indicador. No se inventa un número. |

---

## 3. Circuito objetivo

### 3.1 Flujo operativo

```text
┌────────────────────┐
│ Viaje aprobado     │  Alta con fecha y km reales, equipo, chofer y recorrido
└──────────┬─────────┘
           │ Iniciar viaje
           ▼
┌──────────────┐
│ En curso     │  Tramos, combustible, gastos, cuenta corriente y otros haberes
└──────┬───────┘
       │  Cerrar con fecha y km de regreso
       ▼
┌──────────────┐
│ Finalizado   │  Lectura generada, liquidación y margen calculados
└──────────────┘

Cancelado: salida controlada desde Planificado, con motivo.
```

El operador carga viajes que ya fueron aprobados: el alta operativa los crea directamente en `EN_CURSO`, con la fecha y el odómetro real de salida. No hay una etapa de planificación para las altas nuevas. Se conserva el estado `PLANIFICADO` y la acción de inicio separada para los registros preexistentes que ya estén en ese estado; no se migran ni alteran.

### 3.2 Flujo económico

```text
Por tramo:
  flete facturación
  flete liquidación
  comisión = flete liquidación × %
  costos asignados = combustible + gastos imputados al tramo
  margen tramo = flete facturación − costos asignados − comisión

Por viaje:
  facturado = suma de fletes de facturación
  comisión = suma de comisiones de los tramos
  combustible = suma de cargas del viaje
  gastos = suma de gastos del viaje
  otros haberes = suma de haberes del viaje
  margen viaje = facturado − combustible − gastos − comisión − otros haberes

Liquidación del chofer:
  comisión
  + otros haberes
  + gastos pagados por el chofer
  + devoluciones
  − adelantos
  ± ajustes
  = total a liquidar
```

### 3.3 Ejemplo numérico

Un viaje con dos tramos:

| Concepto | Tramo 1 | Tramo 2 | Total |
|---|---:|---:|---:|
| Flete facturación | 1.000.000 | 600.000 | 1.600.000 |
| Flete liquidación | 800.000 | 500.000 | 1.300.000 |
| Comisión 14% | 112.000 | 70.000 | 182.000 |
| Combustible asignado | 200.000 | 120.000 | 320.000 |
| Gastos asignados | 50.000 | 30.000 | 80.000 |
| **Margen del tramo** | **638.000** | **380.000** | **1.018.000** |

Costos sin asignar: 0. Margen del viaje: `1.600.000 − 320.000 − 80.000 − 182.000 = 1.018.000` (63,6%).

Si además hay otros haberes por 20.000, el margen del viaje baja a 998.000 (62,4%).

Liquidación del chofer:

```text
Comisión                         182.000
Otros haberes                     20.000
Gastos pagados por el chofer      80.000
Adelantos                       −200.000
Devoluciones                      30.000
Total a liquidar                 112.000
```

Los adelantos no cambian el margen del viaje; solo la liquidación final.

---

## 4. Estado actual implementado

### 4.1 Qué existe hoy

| Pieza | Estado | Dónde |
|---|---|---|
| Viajes operativos con estados Planificado / En curso / Finalizado / Cancelado; las altas nuevas empiezan En curso | Implementado | `viajes_operativos`, `OperationalTrips`, `CreateTrip` |
| Numeración correlativa por empresa | Implementado | `viajes_operativos_numeracion` |
| Asignación de tractor, semirremolques (composición) y choferes | Implementado | `viaje_operativo_asignaciones` |
| Porcentaje de liquidación con vigencia | Implementado | `viajes_porcentajes_liquidacion_chofer` |
| Copia del porcentaje en el viaje | Implementado | `viaje_operativo_asignaciones.porcentaje_liquidacion` |
| Paradas / recorrido | Implementado | `viaje_operativo_paradas` |
| Tramos con dos fletes (facturación y liquidación) | Implementado | `viaje_operativo_tramos`, `TripLeg` |
| Combustible por viaje, multi-moneda | Implementado | `viaje_operativo_combustible` |
| Gastos por viaje, multi-moneda | Implementado | `viaje_operativo_gastos` |
| Imputación opcional de combustible y gastos a un tramo | Implementado | `viaje_operativo_combustible.tramo_id`, `viaje_operativo_gastos.tramo_id` |
| Caja del chofer: anticipo, retiro, devolución | Implementado | `viaje_operativo_caja_movimientos` |
| Otros haberes del viaje | Implementado | `viaje_operativo_otros_haberes`, `TripOtherEarning` |
| Alta de viaje aprobado directamente En curso con salida y km reales | Implementado | `CreateTrip`, `Trip::startImmediately` |
| Inicio separado de viajes preexistentes `PLANIFICADO` | Conservado para registros anteriores | `StartTrip` |
| Cierre del viaje con km de regreso | Implementado | `FinishTrip` |
| Lectura del equipo al cerrar, con origen `VIAJE` | Implementado | `CodeIgniterTripReadingRecorder` → `RegisterReadingHandler` |
| Liquidación final del chofer | Implementado | `TripSettlement` |
| Margen operativo por viaje y por tramo | Implementado | `GetOperationalTrip` |
| Cálculo de consumo | Implementado | `GetOperationalTrip` |
| Costos sin asignar, mostrados aparte | Implementado | `GetOperationalTrip` |
| Edición del porcentaje después de crear el viaje | Implementado | `UpdateTripAssignment` |
| Vista por dominio y por chofer | Implementado | `OperationalTripFilters`, `ListOperationalTrips` |
| Historial legacy importado, solo lectura | Implementado | `viajes`, `viaje_tramos`, etc. |
| Cuenta corriente legacy importada, preservando columnas, monedas y cotizaciones | Implementado | `viaje_cuenta_corriente`, `viaje_cuenta_corriente_tramos` (`t11`, `t12`) |
| Otros haberes legacy importados | Implementado | `viaje_otros_haberes` (`t13`) |
| Facturación histórica importada y vínculo con el viaje | Implementado | `viajes_facturas`, `viaje_factura_viaje`, `ReviewTripInvoices` |
| Rentabilidad histórica legacy | Implementado, solo legacy | `GetTripProfitability` |
| Dashboard de viajes | Implementado | `TripsDashboard` |

### 4.2 Qué falta para el circuito descrito

Los diez puntos que esta sección listaba el 21/10 están implementados y cubiertos por pruebas. Lo que
queda es lo que la Fase 2 define y lo que depende de una respuesta del cliente:

| Falta | Impacto | Depende de |
|---|---|---|
| Aprobación de la liquidación del chofer | El importe a liquidar se calcula y se muestra, pero nadie lo aprueba: no hay estado ni responsable. | Fase 2 de la sección 12.2 |
| Pago de la liquidación | No hay registro de pagos, ni comprobante de pago, ni impresión de la liquidación. | Fase 2 de la sección 12.2 |
| Permiso `viajes.liquidar` | Sin el permiso no se puede separar la aprobación del resto de los permisos de viajes. | Fase 2 de la sección 12.2 |
| Definición de la liquidación por viaje o por período | Cambia la pantalla y el modelo de la Fase 2. | Sección 16, pregunta al cliente |
| Impresión de la liquidación | No hay documento imprimible de la liquidación. | Sección 16, pregunta al cliente |

### 4.3 Diferencia con la pantalla de rentabilidad actual

`GetTripProfitability` calcula, solo para viajes legacy:

```text
margen = ingresos facturados − (combustible + gastos + otros haberes + comisión)
```

Es un análisis de margen de empresa. No es la liquidación del chofer y no incluye adelantos ni cuenta corriente. En el circuito nuevo, el margen se calcula sobre viajes operativos y la liquidación se muestra por separado.

---

## 5. Decisiones de diseño confirmadas

1. **Dos fletes por tramo:** uno para facturación y otro para liquidación.
2. **El porcentaje del chofer se aplica al flete de liquidación del tramo**, no al de facturación.
3. **El porcentaje sale del maestro de choferes**, pero se puede modificar para el viaje y queda guardado como copia.
4. **El combustible y los gastos pueden imputarse opcionalmente a un tramo.**
5. **Si un costo no se imputa a un tramo, suma al viaje pero no al margen del tramo.** No se prorratea automáticamente.
6. **El margen del viaje incluye todos los costos del viaje**, estén o no asignados a un tramo.
7. **Los adelantos y devoluciones no son costo del viaje.** Afectan la liquidación del chofer.
8. **El cierre del viaje requiere km de regreso y fecha de regreso.** Genera una lectura del equipo con origen `VIAJE`.
9. **El viaje finalizado conserva su historial y no se edita.** Las correcciones se hacen con ajustes o reversiones auditadas.
10. **No se migra la cuenta corriente legacy como libro operativo.** El análisis del backup concluyó que no reconcilia. La cuenta nueva se implementa limpia; la historia legacy queda de solo lectura.

---

## 6. Modelo de dominio

### 6.1 Agregado Viaje

El viaje es la raíz de consistencia. Contiene:

- Identidad: empresa, sucursal, número correlativo.
- Estado: `PLANIFICADO`, `EN_CURSO`, `FINALIZADO`, `CANCELADO`.
- Fechas planificadas y reales de salida y regreso.
- Km de salida y regreso.
- Asignaciones: tractor, composición de semirremolques (hasta 3: bitrén o tridem) y choferes.
- Tramos.
- Observaciones y motivo de cancelación.
- Snapshots de cierre: facturado, costo, comisión, liquidación y margen.

### 6.2 Entidades hijas

| Entidad | Responsabilidad |
|---|---|
| `TripAssignment` | Equipo o chofer asignado, vigencia, motivo y porcentaje de liquidación del chofer. |
| `TripLeg` (nuevo) | Tramo con origen, destino, cliente, km, flete de facturación y flete de liquidación. |
| `TripFuelLoad` | Carga de combustible, con moneda, cotización, importe ARS y tramo opcional. |
| `TripExpense` | Gasto del viaje, con moneda, cotización, importe ARS, pagador y tramo opcional. |
| `TripLedgerEntry` (nuevo) | Movimiento de cuenta corriente: adelanto, devolución, gasto a favor, otro haber o ajuste. |
| `TripOtherEarning` (nuevo) | Otros haberes del viaje, separados de la cuenta corriente para poder mostrarlos como en la pantalla legacy. |
| `TripSettlement` (nuevo, fase 2) | Liquidación calculada, con estado, aprobación y pagos. |

### 6.3 Invariantes

- El viaje necesita empresa, sucursal, número y al menos un chofer.
- El porcentaje de liquidación debe estar entre 0 y 100.
- El flete de facturación y el de liquidación no pueden ser negativos.
- La suma de los tramos no puede quedar vacía al cerrar.
- El km de regreso no puede ser menor al km de salida.
- La fecha de regreso no puede ser anterior a la de salida.
- Un tramo pertenece al mismo viaje, empresa y sucursal.
- Un costo imputado a un tramo debe pertenecer al mismo viaje, empresa y sucursal.
- Los importes monetarios usan `DECIMAL`, nunca `FLOAT`.
- La liquidación final se calcula una vez al cerrar y queda guardada como snapshot.
- Un viaje finalizado no se modifica; se corrige con movimientos o reversiones.

### 6.4 Transiciones de estado

| Desde | Acción | Hacia | Reglas |
|---|---|---|---|
| — | Crear / planificar | `PLANIFICADO` | Número, empresa, sucursal, chofer y tramos mínimos. |
| `PLANIFICADO` | Abrir / iniciar | `EN_CURSO` | Fecha real y km de salida obligatorios. |
| `PLANIFICADO` | Cancelar | `CANCELADO` | Motivo obligatorio. |
| `EN_CURSO` | Cerrar | `FINALIZADO` | Fecha y km de regreso; lectura generada; resumen económico. |
| `EN_CURSO` | Cancelar | — | No permitido: ya salió. Se debe cerrar o registrar una incidencia. |

---

## 7. Modelo de datos propuesto

### 7.1 Tabla nueva: `viaje_operativo_tramos`

| Columna | Tipo | Regla |
|---|---|---|
| `id` | INT UNSIGNED AI | PK |
| `viaje_id` | INT UNSIGNED | FK a `viajes_operativos` |
| `empresa_id` | INT UNSIGNED | Scope |
| `sucursal_id` | INT UNSIGNED | Scope |
| `orden` | INT UNSIGNED | Consecutivo desde 1 |
| `codigo_origen_destino` | INT UNSIGNED NULL | Código de ruta del sistema anterior, si se conoce |
| `localidad_origen_id` / `localidad_destino_id` | INT UNSIGNED NULL | Referencias al maestro geográfico `localidades` |
| `cliente_id` | INT UNSIGNED | Un cliente asociado al tramo |
| `origen` / `destino` | VARCHAR(200) | Nombre conservado para mostrar e imprimir |
| `km` | DECIMAL(14,2) NULL | Kilómetros informados |
| `toneladas` | DECIMAL(14,2) NULL | Toneladas informadas |
| `moneda` | CHAR(3) | Default `ARS` |
| `cotizacion` | DECIMAL(18,6) | Default 1 |
| `flete_facturacion` | DECIMAL(18,4) | Importe en moneda |
| `flete_facturacion_ars` | DECIMAL(18,4) | Snapshot ARS |
| `flete_liquidacion` | DECIMAL(18,4) | Importe en moneda |
| `flete_liquidacion_ars` | DECIMAL(18,4) | Snapshot ARS |
| `comision_ars` | DECIMAL(18,4) | Calculada con el % del chofer |
| `observaciones` | VARCHAR(500) NULL | |
| `created_at` / `updated_at` | DATETIME | Auditoría |
| `created_by` / `updated_by` | INT NULL | Auditoría |

`comision_ars` y los importes ARS se calculan con el porcentaje vigente del viaje y se congelan al cerrar. Mientras el viaje está abierto se recalculan al vuelo; no se considera un dato definitivo hasta el cierre.

Índices:

- Único `(viaje_id, orden)`.
- `(empresa_id, sucursal_id)`.
- `(empresa_id, cliente_id)`.
- FK `viaje_id` a `viajes_operativos` con `ON DELETE CASCADE`.
- FK `empresa_id` a `empresas`.
- FK `cliente_id` a `clientes`.

### 7.2 Modificaciones a tablas existentes

| Tabla | Cambio |
|---|---|
| `viaje_operativo_combustible` | Agregar `tramo_id` nullable, FK al tramo del mismo viaje. |
| `viaje_operativo_gastos` | Agregar `tramo_id` nullable y `pagado_por` (`EMPRESA`, `CHOFER`, `CAJA`). Conservar `pagado_de_caja` durante la transición. |
| `viaje_operativo_asignaciones` | Ya tiene `porcentaje_liquidacion`. Agregar `es_responsable_liquidacion` si se decide un chofer principal. |
| `viajes_operativos` | Agregar snapshots de cierre: `facturado_ars`, `combustible_ars`, `gastos_ars`, `comision_ars`, `otros_haberes_ars`, `liquidacion_ars`, `margen_ars`, `cerrado_at`, `cerrado_by`. |

### 7.3 Tabla nueva: `viaje_operativo_cuenta_corriente`

Libro único de movimientos entre empresa y chofer.

| Columna | Tipo | Regla |
|---|---|---|
| `id` | INT UNSIGNED AI | PK |
| `viaje_id` | INT UNSIGNED | FK |
| `empresa_id` / `sucursal_id` | INT UNSIGNED | Scope |
| `empleado_id` | INT UNSIGNED | Chofer |
| `fecha` | DATETIME | Fecha del movimiento |
| `tipo` | VARCHAR(30) | `ADELANTO`, `DEVOLUCION`, `GASTO_CHOFER`, `AJUSTE` |
| `concepto` | VARCHAR(120) | Texto legible |
| `descripcion` | VARCHAR(500) NULL | Detalle |
| `debito` | DECIMAL(18,4) | A favor de la empresa |
| `credito` | DECIMAL(18,4) | A favor del chofer |
| `moneda` | CHAR(3) | Moneda original |
| `cotizacion` | DECIMAL(18,6) | Cotización aplicada |
| `importe_ars` | DECIMAL(18,4) | Snapshot |
| `origen` | VARCHAR(20) | `MANUAL`, `CAJA`, `GASTO`, `AJUSTE` |
| `referencia_id` | INT NULL | Vínculo con caja/gasto |
| `created_at` / `created_by` | DATETIME / INT | Auditoría |

Regla de saldo:

```text
saldo = suma(creditos) − suma(debitos)
saldo > 0  -> la empresa le debe al chofer
saldo < 0  -> el chofer le debe a la empresa
```

### 7.4 Tabla nueva: `viaje_operativo_otros_haberes`

| Columna | Tipo |
|---|---|
| `id` | INT UNSIGNED AI |
| `viaje_id` / `empresa_id` / `sucursal_id` | INT UNSIGNED |
| `empleado_id` | INT UNSIGNED |
| `fecha` | DATE |
| `concepto` | VARCHAR(120) |
| `importe` / `importe_ars` | DECIMAL(18,4) |
| `moneda` / `cotizacion` | CHAR(3) / DECIMAL(18,6) |
| `observaciones` | VARCHAR(500) NULL |
| `created_at` / `created_by` | Auditoría |

### 7.5 Tabla opcional fase 2: `viaje_operativo_liquidaciones`

Para aprobar, pagar y auditar la liquidación:

| Columna | Tipo |
|---|---|
| `id` | INT UNSIGNED AI |
| `viaje_id` / `empresa_id` / `sucursal_id` | INT UNSIGNED |
| `empleado_id` | INT UNSIGNED |
| `fecha` | DATETIME |
| `porcentaje` | DECIMAL(5,2) |
| `base_liquidacion_ars` | DECIMAL(18,4) |
| `comision_ars` | DECIMAL(18,4) |
| `otros_haberes_ars` | DECIMAL(18,4) |
| `gastos_favor_ars` | DECIMAL(18,4) |
| `adelantos_ars` | DECIMAL(18,4) |
| `devoluciones_ars` | DECIMAL(18,4) |
| `ajustes_ars` | DECIMAL(18,4) |
| `total_ars` | DECIMAL(18,4) |
| `estado` | `BORRADOR`, `CALCULADA`, `APROBADA`, `PAGADA`, `ANULADA` |
| `observaciones` | VARCHAR(500) NULL |

---

## 8. Reglas de cálculo

### 8.1 Comisión

```text
comision_tramo = flete_liquidacion_tramo × (porcentaje_chofer / 100)
comision_viaje = suma(comision_tramo)
```

El porcentaje se toma del maestro de choferes al crear el viaje, se copia al viaje y se puede modificar mientras el viaje esté `PLANIFICADO` o `EN_CURSO`. Al cerrar queda congelado.

### 8.2 Margen por tramo

```text
costos_asignados_tramo = combustible_tramo + gastos_tramo
margen_tramo = flete_facturacion_tramo − costos_asignados_tramo − comision_tramo
margen_tramo_pct = margen_tramo / flete_facturacion_tramo × 100
```

Si no hay flete de facturación, el margen no se muestra como número: `SIN DATOS SUFICIENTES`.

### 8.3 Margen del viaje

```text
combustible_total = suma(combustible del viaje)
gastos_total = suma(gastos del viaje)
comision_total = suma(comisiones)
otros_haberes_total = suma(otros haberes)
costo_viaje = combustible_total + gastos_total + comision_total + otros_haberes_total
margen_viaje = facturado_total − costo_viaje
margen_viaje_pct = margen_viaje / facturado_total × 100
```

El margen del viaje incluye los costos asignados y los no asignados. Para conciliar:

```text
suma(margen_tramo) − costos_sin_asignar − otros_haberes = margen_viaje
```

Se debe mostrar por separado "costos sin asignar" para que la conciliación sea visible.

### 8.4 Liquidación final del chofer

```text
comision
+ otros_haberes
+ gastos pagados por el chofer
+ devoluciones
− adelantos
± ajustes
= total a liquidar
```

Los gastos pagados por el chofer son un crédito a su favor. Los gastos pagados por la empresa no generan crédito. Los gastos pagados con la caja del viaje consumen el adelanto y se registran como crédito dentro de la cuenta corriente.

### 8.5 Redondeo y moneda

- Importes persistidos como `DECIMAL(18,4)`.
- Cotización `DECIMAL(18,6)`.
- Se conserva siempre el importe original, la moneda, la cotización y el importe ARS.
- Los totales se redondean una sola vez al final, a 2 decimales para mostrar.
- No se recalculan movimientos históricos cuando cambia una cotización.
- Si un dato mínimo falta, se muestra `SIN DATOS SUFICIENTES`; no se asume cero.

### 8.6 Múltiples choferes

El modelo actual permite varios choferes por viaje. Para la liquidación se debe definir una de estas reglas:

- **A (recomendada para v1):** un chofer responsable de la liquidación. Los demás son operativos y no reciben comisión en este viaje.
- **B:** cada chofer tiene su porcentaje y su base de liquidación se reparte por kilómetros o días de relevo.
- **C:** cada chofer liquida su propia cuenta corriente y su propia comisión por tramo.

Hasta que se defina, el sistema debe mostrar la liquidación solo si hay un chofer responsable; si hay varios, muestra `SIN DATOS SUFICIENTES` y explica que falta la regla de reparto.

---

## 9. Casos de uso

| Caso de uso | Actor | Resultado |
|---|---|---|
| `AbrirViaje` | Administración / operaciones | Crea el viaje, número, asignaciones, % y km de salida. |
| `AgregarTramo` | Administración / operaciones | Agrega un tramo con dos fletes y moneda. |
| `IniciarViaje` | Administración / operaciones | Pasa a En curso, registra fecha real y km de salida. |
| `RegistrarCombustible` | Administración / operaciones | Carga litros, importe, moneda y tramo opcional. |
| `RegistrarGasto` | Administración / operaciones | Carga gasto, pagador y tramo opcional. |
| `RegistrarMovimientoCuentaCorriente` | Administración / operaciones | Registra adelanto, devolución, gasto a favor o ajuste. |
| `RegistrarOtroHaber` | Administración / operaciones | Registra un haber adicional del viaje. |
| `CerrarViaje` | Administración / operaciones | Registra fecha y km de regreso, genera lectura, congela la liquidación. |
| `ConsultarLiquidacion` | Administración / operaciones | Muestra comisión, haberes, gastos a favor, adelantos y total. |
| `ConsultarMargen` | Administración / operaciones | Muestra margen por tramo y por viaje, con costos sin asignar. |
| `ListarViajesPorDominio` | Administración / operaciones | Filtra y agrupa los viajes por dominio y chofer. |
| `AprobarLiquidacion` | Responsable autorizado | Fase 2: aprueba la liquidación. |
| `RegistrarPagoLiquidacion` | Responsable autorizado | Fase 2: registra pagos parciales o totales. |

---

## 10. Pantallas y navegación

### 10.1 Gestión de viajes por dominio

```text
┌─────────────────────────────────────────────────────────────────────┐
│ Viajes · Gestión de viajes                                          │
│ [Nuevo viaje]   Dominio: [Todos ▾]  Chofer: [Todos ▾]  Estado: [▾]  │
├──────────────────┬──────────────────────────────────────────────────┤
│ Dominios         │ Viajes                                           │
│ ● Todos          │ Nro  Salida     Regreso    Chofer     Km   Margen│
│ ○ AC 532 DD      │ 110  15/10/26   18/10/26   Montiel   4.321  ...  │
│ ○ AB 499 OK      │ 109  12/10/26   14/10/26   Otro      3.980  ...  │
│ ○ AA PRUEBA      │ ...                                              │
├──────────────────┴──────────────────────────────────────────────────┤
│ Abiertos 2 · Facturado $... · Costo $... · Margen $...              │
└─────────────────────────────────────────────────────────────────────┘
```

Reglas:

- "Todos" muestra todos los viajes de la empresa y sucursales autorizadas.
- Al elegir un dominio, se filtran los viajes de ese equipo.
- El chofer se puede filtrar dentro del dominio.
- La lista muestra margen solo si hay datos suficientes.
- Desde cada fila se abre el detalle del viaje.

### 10.2 Nuevo / abrir viaje

```text
┌ Datos del viaje ────────────────────────────────────────────────────┐
│ Empresa: PAWLUK DUBLIN MARIELA ELISA                                │
│ Dominio: [AC 532 DD ▾]     Nro: #110 (se confirma al guardar)       │
│ Fecha salida: [21/10/2026 08:00]   Km salida: [102.896]             │
│ Chofer: [Montiel, Rodrigo ▾]       % liquidación: [14,00]           │
│ Sucursal: [Argentina ▾]            Acoplado: [AC 222 AC ▾]          │
├ Tramos ─────────────────────────────────────────────────────────────┤
│ # Ruta ant. Localidad origen → destino  Cliente  Km/Tns Fact.     │
│ 1 947       AMEPRE BR → BUENOS AIRES ARG 324      —      3.850.000 │
│ 2 154       Zárate → Córdoba             4        —      1.291.784 │
│ [+ Agregar tramo]                                                   │
├─────────────────────────────────────────────────────────────────────┤
│ [Iniciar viaje]                                                    │
└─────────────────────────────────────────────────────────────────────┘
```

Reglas:

- Al elegir el dominio se completa empresa, sucursal, chofer, % y último km.
- El % se puede editar antes de guardar.
- Cada tramo selecciona una localidad de origen y otra de destino desde `localidades`, un solo cliente y sus fletes separados. Se persisten los IDs para que los reportes puedan agrupar salidas por localidad; se conservan nombres para mostrar e imprimir.
- La búsqueda de localidades es remota y devuelve coincidencias mientras se escribe; no carga el catálogo completo en el navegador. Los resultados muestran localidad, contexto administrativo, provincia/departamento y país para distinguir nombres repetidos. El catálogo se importa globalmente para Argentina, Brasil, Chile y Uruguay desde Administración global.
- El código del sistema anterior es opcional. Kilómetros y toneladas son datos opcionales y no se infieren desde ese código.
- Las paradas operativas son hitos separados y no reemplazan los tramos de flete.
- Una futura distancia estimada de ruta basada en OSM debe quedar separada de los kilómetros reales medidos por odómetro.
- El viaje se registra aprobado y ya iniciado: se pide fecha real y km de salida, y queda En curso al guardar.

### 10.3 Viaje en curso

```text
┌ Viaje #110 · AC 532 DD · EN CURSO ──────────────────────────────────┐
│ Salida 15/10 08:00 · Km 102.896 · Chofer Montiel · 14%               │
│ [Tramos] [Combustible] [Gastos] [Cuenta corriente] [Resumen]        │
├ Resumen económico ──────────────────────────────────────────────────┤
│ Facturado              $1.600.000                                   │
│ Comisión (14%)         $  182.000                                   │
│ Combustible            $  320.000                                   │
│ Gastos                 $   80.000                                   │
│ Otros haberes          $   20.000                                   │
│ Costos sin asignar     $        0                                   │
│ Margen del viaje       $  998.000  (62,4%)                          │
│ Liquidación chofer     $  112.000                                   │
├ Acciones ───────────────────────────────────────────────────────────┤
│ [Cerrar viaje]                                                      │
└─────────────────────────────────────────────────────────────────────┘
```

Tabs:

- **Tramos:** tabla con origen, destino, km, facturación, liquidación, comisión, costos asignados y margen.
- **Combustible:** carga y listado; selector de tramo opcional.
- **Gastos:** carga y listado; selector de tramo opcional y pagador.
- **Cuenta corriente:** movimientos, saldo y filtros por tipo.
- **Resumen:** liquidación, margen y conciliación.

### 10.4 Cerrar viaje

```text
┌ Cerrar viaje #110 ──────────────────────────────────────────────────┐
│ Fecha de regreso: [18/10/2026 18:30]   Km regreso: [107.217]        │
│ Km recorridos: 4.321                                                │
│                                                                     │
│ ⚠ No hay cargas de combustible. Confirmá si corresponde.            │
│ ⚠ Hay 1 gasto sin tramo asignado.                                   │
│                                                                     │
│ Facturado $1.600.000 · Costo $602.000 · Margen $998.000             │
│ Liquidación chofer $112.000                                         │
│                                                                     │
│ [Cancelar]                    [Cerrar y generar lectura]            │
└─────────────────────────────────────────────────────────────────────┘
```

Reglas:

- La fecha y el km de regreso son obligatorios.
- El km de regreso no puede ser menor al de salida.
- Se muestran advertencias si faltan combustible o gastos, pero no se bloquea el cierre si el usuario confirma.
- Al cerrar se genera una lectura del equipo con origen `VIAJE`.
- Se guardan los snapshots de facturación, costo, comisión, liquidación y margen.

### 10.5 Liquidación del chofer

```text
┌ Liquidación del chofer · Viaje #110 ────────────────────────────────┐
│ Comisión 14% sobre $1.300.000          $ 182.000                    │
│ Otros haberes                          $  20.000                    │
│ Gastos pagados por el chofer           $  80.000                    │
│ Adelantos                              $−200.000                    │
│ Devoluciones                           $  30.000                    │
│ Ajustes                                $       0                    │
│ Total a liquidar                       $ 112.000                    │
│                                                                     │
│ [Aprobar]  [Registrar pago]  [Imprimir]                             │
└─────────────────────────────────────────────────────────────────────┘
```

### 10.6 Historial legacy

Se conserva como está: solo lectura, con viajes, tramos, combustible, gastos, cuenta corriente, comprobantes y otros haberes importados. No se recalcula ni se mezcla con el circuito nuevo.

---

## 11. Permisos y aislamiento

- `viajes.ver`: consultar viajes, tramos, costos, liquidaciones y márgenes.
- `viajes.operar`: crear, abrir, iniciar, cargar costos y cerrar viajes.
- `viajes.liquidar` (nuevo, fase 2): aprobar y pagar liquidaciones.
- `viajes.maestros.ver` / `viajes.maestros.editar`: catálogos.
- Toda consulta y comando valida `empresa_id` y `sucursal_id` contra el actor.
- No se aceptan IDs de otra empresa aunque vengan en el POST.
- Los costos y tramos validan que el viaje, el tramo y el cliente pertenezcan a la misma empresa y sucursal.
- El Superadministrador global no hereda permisos de operación de una empresa.

---

## 12. Migraciones y compatibilidad

### 12.1 Fase 1 — Circuito operativo y económico

1. Crear `viaje_operativo_tramos`.
2. Agregar `tramo_id` a `viaje_operativo_combustible` y `viaje_operativo_gastos`.
3. Agregar `pagado_por` a `viaje_operativo_gastos`.
4. Crear `viaje_operativo_cuenta_corriente`.
5. Crear `viaje_operativo_otros_haberes`.
6. Agregar snapshots de cierre a `viajes_operativos`.
7. Agregar permisos y rutas de cierre.
8. Migrar la UI de paradas a tramos; conservar paradas como hitos operativos.
9. Backfill: los viajes existentes quedan sin tramos y se muestran como `SIN DATOS SUFICIENTES` para margen.

### 12.2 Fase 2 — Liquidación y pagos

1. Crear `viaje_operativo_liquidaciones`.
2. Agregar estados, aprobación, pagos parciales y anulaciones.
3. Agregar permisos de liquidación.
4. Agregar impresión/exportación.

### 12.3 Compatibilidad legacy

- No se migra `t11`, `t12`, `t13` ni `t15/t16` como libro operativo.
- El histórico legacy sigue disponible en `/viajes` y `/viajes/rentabilidad`.
- La nueva cuenta corriente empieza desde cero por empresa.
- Si se necesita historia de cuenta corriente, se hace una conciliación aparte y se documenta el origen.

---

## 13. Pruebas y criterios de aceptación

### 13.1 Dominio

- El porcentaje aplicado es el del viaje, no el vigente al momento del cálculo.
- La comisión se calcula sobre el flete de liquidación.
- El margen por tramo usa solo costos asignados y la comisión del tramo.
- El margen del viaje usa todos los costos y muestra los no asignados.
- La liquidación suma comisión, haberes, gastos a favor, devoluciones y resta adelantos.
- El saldo de cuenta corriente respeta débitos y créditos.
- Km de regreso menor al de salida se rechaza.
- Viaje finalizado no se edita.

### 13.2 Aplicación

- Crear viaje con uno o más tramos.
- Iniciar viaje con km de salida.
- Cargar combustible y gasto con y sin tramo.
- Cargar adelanto, devolución, gasto a favor y otro haber.
- Cerrar viaje y generar lectura.
- Consultar margen por tramo y viaje.
- Consultar liquidación.
- Rechazar datos de otra empresa o sucursal.

### 13.3 Base de datos

- Migraciones desde cero y rollback.
- FKs compuestas por empresa.
- Índices por viaje, tramo, empresa y fecha.
- Importes `DECIMAL`, no `FLOAT`.
- Snapshots ARS consistentes.

### 13.4 HTTP/UI

- Rutas protegidas por permisos.
- CSRF en todos los POST.
- Formularios repueblan datos ante error.
- Mobile y escritorio.
- Mensajes humanos y accionables.
- `SIN DATOS SUFICIENTES` cuando falta información.

---

## 14. Riesgos y decisiones abiertas

| Tema | Estado | Recomendación |
|---|---|---|
| Múltiples choferes por viaje | Abierto | v1: un chofer responsable de la liquidación; el resto solo operativo. |
| Cierre con costos faltantes | Abierto | Advertencia con confirmación, no bloqueo duro. |
| Moneda de los fletes | Abierto | v1: ARS; dejar campos de moneda/cotización para no bloquear el futuro. |
| Otros haberes por tramo o por viaje | Abierto | v1: por viaje; si se necesita por tramo, agregar `tramo_id`. |
| Aprobación y pago de liquidación | Fase 2 | No mezclar con el cierre operativo. |
| Contabilizado / fecha contable | Fuera de alcance | No traer al circuito operativo salvo pedido explícito. |
| Datos legacy de cuenta corriente | No migrar | Implementar cuenta nueva y conciliar historia aparte. |
| Nombre de la pantalla | Abierto | “Gestión de viajes” y “Liquidaciones” en el módulo Viajes. |

---

## 15. Anexo: comparación legacy / nuevo

| Legacy | Nuevo |
|---|---|
| Dominios por empresa | Selector de dominio en Gestión de viajes. |
| Planilla de viajes | Listado con estado, chofer, km, facturado, costo y margen. |
| Agregar / modificar / borrar viaje | Crear, iniciar, cerrar y cancelar con trazabilidad. |
| Detalle de fletes | Tramos con flete de facturación y flete de liquidación. |
| Gasto de combustible | Combustible con tramo opcional y consumo. |
| Gastos del camión | Gastos con tramo opcional y pagador. |
| Cuenta corriente chofer | Libro único de movimientos con saldo. |
| Otros haberes chofer | Otros haberes del viaje. |
| Liquidación chofer | Liquidación calculada y congelada al cierre. |
| Contabilizado | Fuera de alcance operativo. |
| Rendimiento | Margen por tramo y por viaje, con costos sin asignar. |

---

## 16. Anexo: preguntas para validar con el cliente

1. ¿El viaje se abre y sale en el mismo acto o se permite planificarlo para otra fecha?
2. ¿Siempre hay un solo chofer responsable de la liquidación?
3. ¿Los fletes se cargan en ARS o puede haber tramos en otra moneda?
4. ¿Los otros haberes son por viaje o por tramo?
5. ¿El cierre debe bloquearse si no hay combustible o gastos, o alcanza con una advertencia?
6. ¿La liquidación se paga por viaje o se acumula por chofer/período?
7. ¿Se necesita imprimir la liquidación y el resumen del viaje?
8. ¿Qué dato de “contabilizado” usan hoy y quién lo completa?

---

## 17. Próximo paso sugerido

Validar este documento y, con las respuestas de la sección 16, implementar la Fase 1 en la rama de Viajes:

1. Tramos con dos fletes.
2. Imputación opcional de costos a tramo.
3. Cuenta corriente y otros haberes.
4. Cierre con km de regreso y lectura.
5. Margen por tramo y por viaje.
6. Liquidación del chofer.

La Fase 2 (aprobación y pagos de liquidación) queda para después de validar el circuito operativo.

---

## 18. Registro de revisión

| Fecha | Revisor | Decisión | Observaciones |
|---|---|---|---|
| 21/10/2026 | Mavis | Se documenta el circuito y se listan los diez puntos faltantes para la Fase 1. | Estado: borrador para evaluación. |
| 24/10/2026 | Mavis | Los diez puntos de la Fase 1 quedan implementados y cubiertos por pruebas. La cuenta corriente legacy se migra preservando, sin calcular saldos (D3). | 1478 pruebas, 0 fallos. 88 de dominio y aplicación de Viajes, 57 de base y feature, 28 de frontend. |

Checklist para aprobar la Fase 1:

- [x] Los importes van por tramo y existen los dos fletes.
- [x] El % se aplica al flete de liquidación.
- [x] Los costos se pueden imputar opcionalmente a un tramo.
- [x] El margen del viaje incluye costos asignados y no asignados.
- [x] Los adelantos afectan la liquidación, no el margen del viaje.
- [x] El cierre requiere km de regreso y genera lectura.
- [x] La cuenta corriente legacy no se migra como libro operativo.
- [ ] Las preguntas de la sección 16 están respondidas. **Pendiente del cliente.**

Checklist de la Fase 2 (aprobación y pago de liquidaciones), pendiente:

- [ ] Estado de aprobación de la liquidación, con responsable y fecha.
- [ ] Registro de pagos y comprobante de pago.
- [ ] Permiso `viajes.liquidar`.
- [ ] Impresión de la liquidación.
- [ ] Definición de si la liquidación se paga por viaje o se acumula por chofer y período (pregunta de la sección 16).
