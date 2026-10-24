# Plan de ampliación: absorber los sistemas de neumáticos y de transporte/facturación

**Fecha:** 2026-10-06
**Estado:** propuesta para aprobación. No se escribió código de implementación.
**Reemplaza el alcance de:** `docs/ESPECIFICACION_SISTEMA_MANTENIMIENTO.md` §2.2 y §15
**Documentos relacionados:** `ANALISIS_BACKUP_NEUMATICOS_Y_MIGRACION.md`, `ANALISIS_BACKUP_TRANSPORTE_MARIELA_Y_MIGRACION.md`

---

## 1. Registro del cambio de alcance

### 1.1 Qué se pidió

Incorporar al sistema de mantenimiento dos sistemas legacy que hoy son independientes:

- El **gestor de neumáticos y gomería** (`neumaticos_pawluk`), módulo completo.
- El **ERP de transporte y facturación** (`transporte_mariela_pawluk`), en el alcance acordado: flota, choferes, viajes, combustible, gastos y facturación.

### 1.2 Qué contradice la especificación vigente

La sección 2.2 de la especificación excluye explícitamente las cuatro capacidades que ahora se piden:

| Texto actual de la especificación (§2.2) | Estado |
|---|---|
| "Gestión de neumáticos por posición." | **Se elimina de la lista de exclusiones** |
| "Inventario completo, depósitos y movimientos de stock." | **Se elimina** (acotado a neumáticos) |
| "Compras, cuentas corrientes o pagos a proveedores." | **Se elimina** (acotado a facturación) |
| "Funciones contables o de facturación." | **Se elimina** |

Las secciones 3.3 (organización por dominios), 5 (modelo de datos), 6 (pantallas), 11 (reportes) y 13 (pruebas) deben ampliarse con los dominios nuevos.

La sección 16 exige que toda ampliación material se documente y apruebe antes de desarrollarse. Este documento es esa documentación. **La especificación se actualiza al final de la Fase 0, como parte del trabajo, no antes.**

### 1.3 Consecuencia sobre el calendario

La sección 15 estimaba 8 a 10 semanas para la v1 de mantenimiento. La ampliación agrega tres contextos sobre un sistema que ya tiene diez, con 176 archivos de prueba: el trabajo total se ubica en el orden de **dos a tres veces** el original.

La secuencia de fases de la sección 9 está pensada para que haya valor utilizable en cada corte, sin esperar al final:

| Corte | Se puede usar |
|---|---|
| Fin de Fase 1 | Equipos reales con km vigente, historial y piloto validado |
| Fin de Fase 2 | Viajes cargados, odómetro automático y costo por km |
| Fin de Fase 3 | Neumáticos, gomería como OT y rendimiento por unidad |
| Fin de Fase 4 | Facturación electrónica e ingresos por unidad |

### 1.4 Decisiones pendientes

Ocho puntos (sección 8) dependen de información del cliente que no está en los datos. Ninguno frena la Fase 0 ni la Fase 1: se avanzó con supuestos documentados y se ajustan cuando lleguen las respuestas.

---

## 2. Mapa de contextos

### 2.1 Estado actual

`app/Domain/` tiene hoy 10 contextos: Assets, Measurement, PreventiveMaintenance, WorkOrders, WorkRequests, Employees, Expirations, Importations, Notifications, Chatbot. Más `Infrastructure/` con Organization, Identity, Reports, Dashboard, ReadingControl, DriverPortal, PublicEquipmentAccess, Diagnostics, AppShell.

### 2.2 Contexto nuevo y qué se absorbe

Lo importante del mapa es **qué NO se crea nuevo**:

| Necesidad | Decisión | Motivo |
|---|---|---|
| Flota (28 camiones) | **Se absorbe en `Assets`** | Ya existe `equipos` con marca, modelo, estado, relaciones y QR. Crear una flota paralela duplicaría el activo. |
| Choferes (100) | **Se absorbe en `Employees`** | Ya existe `employees` y `employee_equipment_assignments`. |
| Odómetro | **Se absorbe en `Measurement`** | Ya existe `lecturas_equipo`. Los viajes solo agregan un **origen** nuevo de lectura. |
| Gomería | **Se apoya en `WorkOrders`** | Una reparación es una orden de trabajo. Duplicar el ciclo de vida sería el error más caro del proyecto. |

Resultado: **tres contextos nuevos**, no cinco.

### 2.3 Los tres contextos nuevos

```
                    ┌──────────────────┐
                    │  Organization    │  empresas, sucursales, usuarios, roles
                    └────────┬─────────┘
                             │ (empresa_id, sucursal_id)
   ┌─────────────┬───────────┼───────────┬──────────────┐
   │             │           │           │              │
┌──▼───────┐ ┌───▼────────┐ ┌▼─────────┐ │        ┌─────▼──────┐
│  Assets  │ │ Measurement│ │ WorkOrders│ │        │    Viajes  │  NUEVO
│  equipos │ │  lecturas  │ │  OT      │ │        │ viaje      │
└──┬───────┘ └───▲────────┘ │  tareas  │ │        │ tramo      │
   │              │          │ repuestos│ │        │ combustible│
   │              └──────────┤ cierre   │ │        │ gasto      │
   │   ┌──────────┐          └────┬─────┘ │        └─────┬──────┘
   │   │ Employees│               │       │              │
   └───┤ choferes │───────────────┘       │              │
       └──────────┘                       │              │
                                          │              │
   ┌──────────────┐                       │              │
   │ Neumaticos   │ NUEVO                 │              │
   │  neumático   │──── gomería ─────────┘              │
   │  ubicacion   │                                       │
   │  deposito    │                                       │
   │  colocacion  │                                       │
   └──────┬───────┘                                       │
          │                                               │
          │                                       ┌───────▼──────┐
          │                                       │ Facturacion  │ NUEVO
          │                                       │ comprobante  │
          │                                       │ numeracion   │
          │                                       │ CAE / ARCA   │
          └──────────────► Assets ◄───────────────┤ detalle      │
                          (patente → equipo)       └──────────────┘
```

### 2.4 Contratos entre contextos

Regla que se mantiene sin excepción: **nadie lee tablas de otro contexto desde Presentación**. Todo cruce pasa por un puerto de aplicación o un read model explícito.

| Origen | Destino | Contrato | Nota |
|---|---|---|---|
| `Viajes` → `Assets` | Puerto `EquipoPorPatenteQuery` | Resuelve patente → `equipo_id`. El viaje se registra por patente y el sistema la traduce una vez. | Evita guardar patente duplicada en el viaje. |
| `Viajes` → `Measurement` | Puerto `RegistrarLecturaCommand` | Al cerrar un viaje con km válido, genera una `lecturas_equipo` con `origen = VIAJE`. | **Es el contrato que le da valor al viaje.** |
| `Viajes` → `WorkOrders` | Read model `CostoOperativoPorEquipoQuery` | Publica costo por km por equipo y por período. | No es una OT ni entra en `costo_total`: es costo operativo, no de mantenimiento. |
| `Neumaticos` → `Assets` | Puerto `EquipoPorPatenteQuery` | Resuelve en qué equipo está montado el neumático. | |
| `Neumaticos` → `Measurement` | Puerto `RegistrarLecturaCommand` | La colocación y la extracción de un neumático también son lecturas de km del equipo. | Alimenta el cálculo de rendimiento. |
| `Neumaticos` → `PreventiveMaintenance` | Publica eventos | `NeumaticoColocado`, `NeumaticoRetirado`, `NeumaticoRecapado`. | Rotación y recapado se planifican, no se inventan en la OT. |
| `Neumaticos` → `WorkOrders` | Puerto `AbrirOrdenGomeriaCommand` | Una reparación o recapado genera una OT normal, con `tipo_servicio` de gomería. | Ver 4.3. |
| `Facturacion` → `Assets` | Puerto `EquipoPorPatenteQuery` | Las líneas `UNIDAD: <patente>` del comprobante se asocian al equipo. | Permite facturar y consultar costo por unidad en la misma pantalla. |
| Todos → `Notifications` | Puerto `NotificarEvento` | Avisos de vencimiento, de viaje sin lectura, de factura impaga. | Motor central ya existe; no se duplica. |
| Todos → `Chatbot` | Tools registradas | Consultar km de un equipo, costo por km, estado de un neumático. | Solo lectura por defecto. |

---

## 3. Convención de código a respetar

Verificada en el checkout actual. Los contextos nuevos se escriben exactamente igual que los existentes.

```
app/Domain/<Contexto>/
    <Entidad>.php                 clase de entidad, invariantes y comportamiento
    <ValueObject>.php

app/Application/<Contexto>/
    <Accion>Command.php           entrada
    <Accion>Handler.php           orquestación y transacción
    <Accion>Result.php            salida
    <Consulta>Query.php / <Consulta>Handler.php
    Port/<Puerto>.php             interfaz de salida (repositorio, reloj, ARCA)

app/Infrastructure/<Contexto>/
    CodeIgniter<Entidad>Repository.php
    CodeIgniter<Contexto>Transaction.php
    CodeIgniter<Contexto>ReadModel.php

app/Presentation/<Contexto>/        (solo donde haga falta, hoy solo hay ReadingControl)

tests/unit/<Contexto>/             espeja la estructura de app/
tests/database/                    integración contra MySQL real (hoy hay 2 archivos)
```

Reglas que no se negocian: el dominio no importa CodeIgniter ni nada de infraestructura; los importes van en `DECIMAL`; toda mutación sensible va en transacción; nada de CQRS ni eventos asíncronos.

---

## 4. Contexto `Viajes`

### 4.1 Modelo

**Agregado `Viaje`** (raíz): un viaje de una unidad, con fecha de salida y regreso, odómetro de salida y de regreso, chofer asignado, estado de contabilización y porcentaje del chofer.

Entidades hijas dentro del mismo agregado (se guardan en una sola transacción):

- `TramoViaje` — origen, destino, km, importe. Es lo que da el costo por km.
- `CargaCombustible` — fecha, litros, importe, estación, km al momento.
- `GastoViaje` — fecha, concepto, importe.

**Value objects:** `Kilometraje` (no negativo, nunca retrocede), `Importe` (`DECIMAL(14,2)`), `RangoFechas` (regreso nunca anterior a la salida).

### 4.2 Invariantes

1. `km_regreso >= km_salida` cuando ambos están informados.
2. `fecha_regreso >= fecha_salida`. **El legacy tiene 519 viajes que violan esto** (uno sale en 2022 y "regresa" en 2018); en el importador esos viajes se marcan para revisión en vez de rechazarse en silencio.
3. Un viaje no genera lectura si `km_regreso` es 0 o nulo.
4. La lectura generada no puede hacer retroceder el odómetro vigente del equipo. Si lo haría, se rechaza con motivo.
5. Un viaje con combustible y km permite calcular **consumo (l/100 km)** y **costo por km**.

### 4.3 Decisión de diseño: el viaje **no** es una orden de trabajo

Es tentador modelar el viaje como OT. No: un viaje no tiene diagnóstico, ni tareas, ni repuestos, ni cierre técnico. Es un evento operativo. Modelarlo como OT ensuciaría `WorkOrders` y rompería el invariante de "una OT no finaliza sin trabajo realizado".

### 4.4 Tablas nuevas

| Tabla | Origen legacy | Notas |
|---|---|---|
| `viajes` | `t05_viajes` | Getters: dominio, nro viaje, fechas, km, chofer, % |
| `viaje_tramos` | `t06_origenes_destinos_por_viaje` | |
| `viaje_combustible` | `t07_cargas_combustible_por_viaje` | |
| `viaje_gastos` | `t08_gastos_por_viaje` | |
| `viajes_cuenta_corriente` | `t11`, `t12`, `t13` | **Opcional**, ver decisión D3 |

### 4.5 Casos de uso

- `RegistrarViajeCommand` / `RegistrarViajeHandler`
- `RegistrarCargaCombustibleCommand`
- `RegistrarGastoViajeCommand`
- `CerrarViajeCommand` (dispara la lectura)
- `ListarViajesQuery` (por equipo, período, chofer)
- `ObtenerConsumoPorEquipoQuery` (l/100 km y costo por km)
- `RevisarLecturasDerivadasQuery` (los 519 dudosos)

### 4.6 Pantallas

- Listado de viajes con filtros (empresa, equipo, período, chofer).
- Ficha de viaje con tramos, combustible, gastos y resumen de costo por km.
- Indicador **costo por km y consumo por equipo**, que es el aporte nuevo al negocio.
- Bandeja de "lecturas derivadas a revisar" para el caso de datos incoherentes.

---

## 5. Contexto `Neumaticos`

### 5.1 Modelo

**Agregado `Neumatico`** (raíz): identidad propia (`empresa_id` + `numero_neumatico`), marca, modelo, medida, año de fabricación, origen, precio, y su ciclo de vida.

**Ciclo de vida** (los 11 estados que ya define el legacy, que se respetan tal cual):

```
NUEVA ──► 1/2 USO ──► EN REPARACION ──► REPARADO ──┬──► VULCANIZADA
                        │                        ├──► RECAPADA
                        │                        ├──► DESCARTE
                        │                        └──► TRANSFERIDA
                        └────────────────────────────► BAJA DEFINITIVA
NUEVA ──────────────────────────────────────────────► P/VENTA ──► VENDIDA
```

Transiciones válidas en dominio, no en la vista. Un neumático no se borra: se baja.

Entidades y agregados asociados:

- `UbicacionNeumatico` — en qué equipo, lugar, lado, posición, botón.
- `PlanillaColocacion` / `PlanillaExtraccion` — bitácario con km; de ahí sale el **rendimiento** (km entre colocación y extracción), que es el indicador central del módulo.
- `OrdenGomeria` — **se apoya en `WorkOrders`** (ver 5.3).
- `Deposito` y `StockNeumatico` — depósito con stock por posición y entradas/salidas por planilla.

### 5.2 Invariantes

1. Un `Neumatico` no cambia de `numero_neumatico` una vez dado de alta.
2. Solo un aggregation `UbicacionNeumatico` activa por neumático.
3. La fecha de extracción no puede ser anterior a la de colocación.
4. `km_extraccion >= km_colocacion`, o el rendimiento se marca como no confiable.
5. Un Aggregate `StockNeumatico` no puede quedar negativo sin ajuste documentado.
6. Un Aggregate `Neumatico` no pasa directo de NUEVA a BAJA DEFINITIVA: tiene que pasar por RECAPADA, VULCANIZADA o DESCARTE.

### 5.3 Decisión de diseño: gomería **sobre** `WorkOrders`, no un módulo paralelo

El legacy tiene `planilla_gomeria` y `envios_planilla_gomeria` con su propio flujo. **No se replica ese flujo.** Se reutiliza `WorkOrders`, que ya tiene estados, transiciones, tareas, repuestos, costos, cierre, numeración, PDF y notificaciones.

La traducción es:

| Legacy | Destino |
|---|---|
| `planilla_gomeria` (envío) | OT con `origen = GOMERIA`, `tipo_servicio` de gomería, `proveedor_id` |
| `envios_planilla_gomeria` (recepción) | Cierre de la OT con `km_salida` y trabajo realizado |
| `actividad_solicitada` / `actividad_realizada` | `descripcion_solicitada` / `trabajo_realizado` |
| `importe` de gomeria | `costo_repuestos` o `otros_costos` |
| `tipo_de_banda` | Tarea dentro de la OT |

**Motivo:** un recapado es, en el lenguaje del negocio, un mantenimiento. Si se abre un módulo aparte, el equipo pierde el historial unificado: la ficha del camión mostraría el historial de OT pero no el de gomería, y los dos universes de costo quedarían separados. El costo de esa decisión aparece el día que alguien pregunta cuánto costó mantener una unidad.

### 5.4 Tablas nuevas

| Tabla | Origen legacy |
|---|---|
| `neumaticos` | `neumaticos` |
| `neumatico_marcas` / `neumatico_modelos` | `marcas` / `modelos` (los de neumático, no los de máquina) |
| `neumatico_tipos_cubierta` | `tipos_de_cubiertas` (LISA, TACO) |
| `neumaticos_estados` | `estado_actual` (catálogo de 11) |
| `neumatico_ubicaciones` | `ubicacion_de_los_neumaticos` |
| `neumatico_colocaciones` | `historial_cubiertas` |
| `neumatico_historial_km` | `historial_kilometraje_de_neumaticos` |
| `neumatico_tipos_historial` | `historial_tipo_de_cubierta` |
| `depositos` / `deposito_stock` | `depositos` / `stock` |
| `deposito_movimientos` | `planillas` + `detalle_planillas_entrada/salida` |

`historial_estado_cubiertas` se implementa como **bitácora de estados** del agregado `Neumatico`, no como tabla independiente.

### 5.5 Casos de uso

- `RegistrarNeumaticoCommand`, `ActualizarNeumaticoCommand`
- `ColocarNeumaticoCommand` (equipo, posición, km) → genera lectura del equipo
- `ExtraerNeumaticoCommand` (km) → genera lectura y calcula rendimiento
- `CambiarEstadoNeumaticoCommand` (valida la transición)
- `RegistrarEntradaDepositoCommand` / `RegistrarSalidaDepositoCommand`
- `AbrirOrdenGomeriaCommand` (delega en `WorkOrders`)
- `ObtenerRendimientoNeumaticoQuery`

### 5.6 Pantallas

- Padrón de neumáticos con estado, ubicación y rendimiento.
- Ficha del neumático con historial completo de colocaciones y estados.
- Mapa de posiciones del equipo: qué neumático va en qué eje, lado y botón.
- Depósito con stock y movimientos.
- Indicadores: rendimiento promedio por marca/modelo, costo de recapado, antigüedad.

---

## 6. Contexto `Facturacion`

### 6.1 Advertencia: esto tiene una dependencia regulatoria bloqueante

**Las 1.783 facturas del legacy tienen CAE (el 100%),** y cubren doce tipos: FAC-A, FAC-B, FAC-E, NCR-A/B/E, NCP-A, NDB-A/E, NDP-A, NOT-R y FCP-A.

Esto significa que la empresa hoy **emite facturación electrónica autorizada por ARCA**, y en el backup hay `codigo_actividad` y `cae` en cada comprobante.

**Consecuencia directa:** sin el certificado de ARCA y el punto de factura correspondiente, **no se puede seguir facturando**, y los 1.783 CAE históricos deben conservarse para poder reimprimir o validar comprobantes ya emitidos. No es un detalle técnico: es un requisito legal.

Por eso la facturación **va última** en el plan, y su primera tarea no es escribir código sino resolver la situación del certificado.

### 6.2 Modelo

**Agregado `Comprobante`** (raíz): tipo, punto de venta, número, fecha, cliente, forma de pago, bonificación, subtotal, IVA, impuestos, DGR, total, estado, CAE y su vencimiento, datos de exportación.

Entidades hijas: `DetalleComprobante` (líneas), `ReferenciaContable` (alícuota de IVA).

**Value objects:** `NumeroComprobante` (punto de venta + número, único por tipo y empresa), `CAE` (15 caracteres, validación de formato), `Importe`.

**Servicio de dominio:** `CalculoDeNetos` — bonificaciones, alícuotas y DGR. Es la lógica que hoy está repartida entre las vistas `calculo_de_netos`, `facturas_y_recibos` y `descuento_por_cliente_por_marca`.

### 6.3 Hallazgo que define el modelo de facturación

`a22_productos` tiene **3 filas** ("FLETE", "FLETE S/IVA", "* varios"). No hay catálogo de productos real: **cada renglón de factura se escribe como texto libre.**

Los renglones más frecuentes de las 10.309 líneas de detalle son:

| Texto | Renglones |
|---|---|
| `UN FLETE DE BOBINAS DE PAPEL` | 211 |
| `UN FLETE DE VINOS` | 181 |
| `UN FLETE DE FAPLAC MEL MDF` | 167 |
| `UNIDAD: AG007VR` | 164 |
| `UNIDAD: AC532DD` | 157 |
| `UNIDAD: AF117ZK` | 147 |

El patrón `UNIDAD: <patente>` es la observación más útil de todo el backup para el sistema de mantenimiento: **la empresa factura por unidad y por viaje, en texto suelto.** Por eso `Facturacion → Assets` es un contrato de primera clase: si se parsea ese prefijo, cada línea de factura queda asociada a un equipo, y de ahí sale el **ingreso por unidad** para comparar contra el costo de mantenimiento de esa misma unidad.

Y `a03_clientes` tiene **una sola fila**: "Clientes Varios". La gestión de clientes vive fuera de este backup, probablemente en otro sistema.

### 6.4 Invariantes

1. El número de comprobante es único por `(empresa, tipo, punto_venta, numero)`.
2. La numeración se asigna de forma transaccional; dos Emissions simultáneas no pueden obtener el mismo número.
3. Un comprobante con CAE no se modifica ni se anula físicamente: se emite una nota de crédito.
4. `total = subtotal - bonificación + IVA + impuestos - DGR`, con `DECIMAL(14,2)`.
5. Un comprobante con CAE vencido no puede reimprimirse sin el certificado vigente.
6. Un comprobante nunca pasa a `anulado` sin nota de crédito asociada.

### 6.5 Tablas nuevas

| Tabla | Origen legacy | Notas |
|---|---|---|
| `comprobantes_venta` | `a18_comprobantes_de_venta` | Se conservan `cae` y `fecha_vencimiento_cae` |
| `comprobantes_venta_detalle` | `a19_detalle_comprobantes_de_venta` | |
| `comprobantes_numeracion` |implícito (punto de venta + nro) | **Nueva.** El legacy no la tiene: confunde numeración con comprobante |
| `referencias_contables` | `a09_referencias_contables` | Alícuotas de IVA |
| `formas_pago` | `a11_formas_de_pago` | |
| `bonificaciones` | `a10_bonificaciones` | |
| `retenciones` | `a17_retenciones` | DGR |
| `facturacion_clientes` | `a03_clientes` | **Solo 1 fila.** Ver decisión D4 |

### 6.6 Casos de uso

- `EmitirComprobanteCommand` / `EmitirComprobanteHandler`
- `NumerarComprobanteCommand` (transaccional)
- `AnularComprobanteCommand` (exige nota de crédito)
- `RegistrarCaeCommand` (respuesta de ARCA)
- `ObtenerComprobanteQuery` / `ListarComprobantesQuery`
- `IngresosPorUnidadQuery` (contrato con `Assets`)

### 6.7 El puerto de ARCA

La integración con ARCA va como **puerto de salida**, no como llamadas dispersas:

```
Port/ArcaGateway.php
    autorizar(comprobante): CAEVoucher
    consultarEstado(cae): EstadoCae
    anular(comprobante): CAEAnulacion
```

Y su adaptador en `Infrastructure/Facturacion/ArcaGateway.php`. El dominio no sabe qué es SOAP ni un certificado; el caso de uso no depende de nada de ARCA. Es lo que permite testear el flujo de facturación completo sin llamar a la AFIP.

### 6.8 Pantallas

- Emisión de comprobante con cálculo de netos en vivo.
- Lista de comprobantes con filtros por tipo, cliente, período y estado.
- Ficha de comprobante con detalle, CAE, estado ante ARCA y reimpresión.
- Informe de ingresos por unidad, cruzando con el costo de mantenimiento.

---

## 7. Plan de migración de datos

### 7.1 Orden de carga

Sin FKs en el legacy, el orden es manual:

```
1. Organization   → empresas, sucursales, usuarios (contraseñas se resetean)
2. Assets         → tipos de equipo, marcas, modelos, equipos (28)
3. Employees      → choferes (100) + asignaciones
4. Measurement    → lecturas desde viajes (odómetro normalizado)
5. Viajes         → viajes, tramos, combustible, gastos
6. Neumaticos     → catálogos, neumáticos, ubicaciones, colocaciones, depósito
7. Preventive     → planes por equipo, derivados de frecuencia de mantenimiento
8. WorkOrders     → las que existan en el legacy de neumáticos (hoy 0)
9. Facturacion    → referencias contables, numeración, comprobantes y detalle
```

### 7.2 Migraciones de datos y sus trampas

| Migración | Volumen | Trampa |
|---|---|---|
| Equipos | 28 | Falta `codigo` (sintetizar), falta año, chasis y motor. `marca_modelo` es texto libre y hay que parsearlo a mano. |
| Choferes | 100 | `codigo_chofer` no es legajo. Incluye "Joselo Key Sistemas", que es un proveedor. Varios dominios comparten el mismo chofer. |
| Lecturas | ~12.650 | El odómetro **arranca distinto por dominio**. 30% de los viajes tienen fecha inválida. Hay que normalizar y descartar antes de validar el invariante de no retroceso. |
| Viajes | 1.740 + 22.000 hijas | Fechas incoherentes (un viaje de 2022 que "regresa" en 2018). Marcar, no rechazar. |
| Combustible | 10.002 | `codigo_concepto` = 1 o 22 sin catálogo claro. Importes con `litros = 0` en cargas de peaje. |
| Neumáticos | catálogo + padrón | El backup de neumáticos está **vacío**: se importa el modelo, no los datos. |
| Facturación | 17.783 | 39 filas con `cuit_empresa` lleno de espacios. 1 solo cliente. CAE obligatorio: sin certificado no se puede seguir. |

### 7.3 Estrategia de importación

Cada migración de datos se implementa como un **comando de CodeIgniter** (`app/Commands/`), no como un script suelto, para que quede versionado, sea reproducible y pueda registrar en `importaciones` qué se cargó y con qué resultado. Los errores van a `importacion_errores` por fila, como ya está implementado.

**El piloto va primero.** Con 28 camiones no hay excusa para no hacer el piloto controlado que pide la especificación (§17): se cargan 5 a 10 unidades, se validan con el responsable, y recién después el resto.

---

## 8. Decisiones pendientes con el cliente

Ninguna se puede deducir de los datos. Las tres primeras cambian el tamaño del proyecto.

| # | Decisión | Impacto |
|---|---|---|
| **D1** | **Situación del certificado de ARCA.** ¿Sigue vigente? ¿Se renueva? ¿Hay que dar de alta un punto de factura nuevo? Sin esto no hay facturación. | **Bloqueante para Facturación.** |
| **D2** | **Alcance de cobranzas.** ¿Entran recibos, cheques, bancos, cuentas corrientes y caja (`a47`, `a50`, `a51`, `a13`, `a57`), o solo facturación? Son un bounded context aparte (Tesorería) con su propio ciclo. | Define si son 3 contextos nuevos o 4. |
| **D3** | **Cuenta corriente por viaje** (`t11`, `t12`, `t13`, 14.540 filas). Investigada el 6/10/2026: el catálogo de conceptos está vacío (45 códigos sin nombre), las monedas son inconsistentes (tres lecturas dan tres totales distintos) y no reconcilia con el combustible (solo 3 de 1.703 viajes coinciden). Las tablas de liquidación existen y están vacías. **Recomendación: cerrarla antes de migrar, no heredarla.** | Cerrarla antes. Migrar solo viajes, tramos, combustible y gastos, que sí reconcilian. |
| **D4** | **Clientes.** El backup tiene **una** fila ("Clientes Varios") y `a13` tiene 990 movimientos. ¿Dónde están los clientes reales? Sin ellos, la facturación no se puede usar. | Bloqueante para Facturación. |
| **D5** | **¿Una empresa o cuatro?** Las 28 unidades y los 1.740 viajes son de un solo CUIT (PAWLUK DUBLIN MARIELA ELISA). Crear las cuatro deja tres vacías. | Define el scope de `empresa_id`. |
| **D6** | **Sucursales reales.** `a46_sucursales` es la matriz banco × sucursal, no sucursales de la empresa. Hay que definirlas. | Bloqueante para `Organization`. |
| **D7** | **Padrón definitivo de flota.** Las 28 unidades están, pero sin año, chasis ni motor, y hay basura (`AA PRUEBA`). ¿Cuál es la lista real y activa? | Bloqueante para `Assets`. |
| **D8** | **¿Dónde se anota hoy el mantenimiento?** Con 28 camiones operando desde 2022 no hay un solo registro de servicio en ninguno de los dos sistemas. | Define si hay histórico que migrar o si arranca de cero. |

---

## 9. Fases

### Fase 0 — Alcance y contratos

Sin código de implementación.

- [x] Análisis de los dos backups
- [x] Registro del cambio de alcance
- [x] Mapa de contextos y contratos
- [x] Actualizar `ESPECIFICACION_SISTEMA_MANTENIMIENTO.md` (§2.2, §3.3, §5, §6, §11, §13, §15) — cerrado en la versión 1.3
- [x] Actualizar `AGENTS.md`: los tres contextos quedan con su agente propietario
- [x] Escribir los contratos (`Puerto` interfaces) antes de las implementaciones — registro en `CONTRATOS_ENTRE_CONTEXTOS.md`
- [ ] **Resolver D1 a D8 con el cliente** — D3 cerrada; las demás están en `DECISIONES_PENDIENTES_FASE_0.md`

**Cierra cuando** la especificación está actualizada y D1 a D8 tienen respuesta.

> **Estado al 24/10/2026.** Todo lo que no dependía de una respuesta del cliente está cerrado: la especificación
> quedó en versión 1.3, `AGENTS.md` ya tiene los tres contextos con su agente propietario, y se escribieron 5
> interfaces de contrato nuevas más el registro de 3 que ya existían, con su firma y su estado. Queda pendiente
> únicamente la respuesta del cliente: D1, D2, D4, D5, D6, D7 y D8. Las preguntas listas para mandar están en
> `DECISIONES_PENDIENTES_FASE_0.md` §4.

### Fase 1 — Datos base

`Organization`, `Assets`, `Employees`, `Measurement`.

- Importadores de empresas, equipos (28), choferes (100) y lecturas.
- Sintetizar `codigo` de equipo y acordar la ficha técnica con el cliente.
- Normalizar odómetro por dominio y descartar lecturas incoherentes con registro.
- Piloto con 5 a 10 unidades.

**Cierra cuando** el panel muestra los equipos reales con su km vigente y su historial.

### Fase 2 — Viajes

El contexto que le da valor al núcleo del mantenimiento.

- Agregado `Viaje` con tramos, combustible y gastos.
- Contrato `Viajes → Measurement`: cierre de viaje genera lectura.
- Indicador de consumo y costo por km.
- Bandeja de lecturas derivadas a revisar.

**Cierra cuando** se carga un viaje real y el odómetro del equipo se actualiza solo.

### Fase 3 — Neumáticos

- Padrón, ciclo de vida, ubicación y depósito.
- Gomería apoyada en `WorkOrders`.
- Rendimiento por neumático y eventos al plan preventivo.

**Cierra cuando** un recapado se abre como OT, se ejecuta, se cierra y aparece en el historial del equipo.

### Fase 4 — Facturación

- **Empieza por D1: resolver el certificado de ARCA.**
- Comprobantes, detalle, numeración transaccional, cálculo de netos.
- Puerto `ArcaGateway` y su adaptador.
- Ingresos por unidad cruzados con costo de mantenimiento.

**Cierra cuando** se emite un comprobante con CAE válido y el equipo muestra su costo total de operación.

---

## 10. Principios que se mantienen

No cambian con la ampliación:

1. Monolito modular CodeIgniter 4. Los bounded contexts son límites semánticos, no microservicios.
2. `Infrastructure → Application → Domain`. Nunca al revés.
3. Los contextos se referencian por ID y por puerto. No comparten modelos ORM.
4. Importes en `DECIMAL`, nunca `FLOAT`.
5. Nada de lo histórico se borra. Catálogos se inactivan.
6. La información histórica no se borra físicamente.
7. Todo acceso valida `empresa_id` en servidor.
8. Sin CQRS, sin event sourcing, sin broker. Las operaciones multiagregado usan transacción.
9. Los datos de ARCA y los secretos no se versionan.

Lo que sí cambia: el sistema deja de ser solo un planificador de mantenimiento y pasa a ser **el sistema operativo de una empresa de transporte**: flota, viajes, costos, neumáticos, mantenimiento y facturación.

---

## 11. Lo que este plan no cubre

- **Telemetría y GPS.** Fuera de alcance en las dos especificaciones. No hay captura automática de lecturas.
- **Aplicación móvil nativa y modo offline.** El sistema sigue siendo web responsive, como ya está resuelto.
- **Integración entre la app y el ERP en producción.** La idea es **reemplazar** el ERP, no convivir con él. Si la decisión fuera convivir, el enfoque sería distinto (Anti-Corruption Layer e integración, no migración) y habría que redefinir esto.
- **Nómina, liquidaciones a choferes y cuentas corrientes.** Requieren D2 y D3.