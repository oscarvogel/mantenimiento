# Contratos entre contextos

**Fecha:** 24/10/2026
**Estado:** Fase 0 — alcance y contratos.
**Origen:** `docs/PLAN_AMPLIACION_NEUMATICOS_VIAJES_FACTURACION.md` §2.4.

---

## 1. La regla

**Nadie lee tablas de otro contexto desde Presentación.** Todo cruce pasa por un puerto de
aplicación o un contrato de lectura explícito. Un contexto se referencia por ID; no se
comparten modelos ORM, builders ni entidades.

Este documento es el registro de esos contratos: quién inicia, quién responde, cuál es la
firma exacta y quién falta implementar el adaptador.

---

## 2. Dónde vive cada contrato

Se aplica la convención de `app/Application/<Contexto>/Port/`, con dos variantes:

| Variante | Cuándo | Ejemplo |
|---|---|---|
| **Puerto del consumidor** | El consumidor dispara una acción y necesita que el proveedor la ejecute con su propia semántica. El puerto vive en el `Application` del **consumidor** y el adaptador delega al caso de uso del proveedor. | `TripReadingRecorder` → llama a `RegisterReadingHandler` de Measurement. |
| **Contrato del proveedor** | El proveedor expone una capacidad de lectura que varios consumidores necesitan. El puerto vive en el `Application` del **proveedor** y todos lo consumen por la misma interfaz. | `EquipmentByPlateLookup` → responde Assets, lo usan Viajes, Neumáticos y Facturación. |

Se eligió así para no triplicar la misma consulta y para no filtrar la semántica del
proveedor (el origen de una lectura, por ejemplo) hacia el consumidor.

**Los nombres de las interfaces son en inglés**, como el resto del código. El plan usa
nombres en español; la equivalencia está en la tabla siguiente para que la traza se pueda
seguir.

---

## 3. Registro de contratos

| Plan (nombre en español) | Interfaz real | Archivo | Responde | Estado |
|---|---|---|---|---|
| `EquipoPorPatenteQuery` | `EquipmentFleetLookup` | `app/Application/Assets/Port/EquipmentFleetLookup.php` | Assets | **Implementado.** Resuelve por id y por patente. |
| — (nuevo) | `EmployeeDirectoryLookup` | `app/Application/Employees/Port/EmployeeDirectoryLookup.php` | Employees | **Implementado.** Resuelve el chofer. |
| `RegistrarLecturaCommand` (Viajes) | `TripReadingRecorder` | `app/Application/Trips/Port/TripReadingRecorder.php` | Measurement | **Implementado y en uso.** |
| `RegistrarLecturaCommand` (Neumáticos) | `NeumaticReadingRecorder` | `app/Application/Neumaticos/Port/NeumaticReadingRecorder.php` | Measurement | **Contrato escrito. Falta adaptador.** |
| `CostoOperativoPorEquipoQuery` | `OperationalCostByEquipmentQuery` | `app/Application/Trips/Port/OperationalCostByEquipmentQuery.php` | Viajes | **Contrato escrito. Falta adaptador.** |
| `AbrirOrdenGomeriaCommand` | `GomeriaOrderLauncher` + `LaunchGomeriaOrderCommand` | `app/Application/Neumaticos/Port/GomeriaOrderLauncher.php` | WorkOrders | **Contrato escrito. Falta adaptador.** |
| Publicación de eventos de preventivo | `PreventiveNeumaticEventPublisher` | `app/Application/Neumaticos/Port/PreventiveNeumaticEventPublisher.php` | PreventiveMaintenance | **Contrato escrito. Falta adaptador.** |
| `ArcaGateway` (especificación §5.14) | `ArcaGateway` | `app/Application/Facturacion/Port/ArcaGateway.php` | ARCA | **Contrato escrito. Falta adaptador.** |
| `NotificarEvento` | `NotifiableEventPublisher` | `app/Application/Notifications/Port/NotifiableEventPublisher.php` | Notifications | **Ya existía. Implementado.** |
| Tools del chatbot | `ToolRegistry` | `app/Application/Chatbot/Port/ToolRegistry.php` | Chatbot | **Ya existía. Implementado.** |

---

## 4. Firmas

### 4.1 `EquipmentFleetLookup` — responde Assets

```php
public function equipment(int $companyId, int $equipmentId): ?array;
public function equipmentByPlate(int $companyId, string $plate): ?array;
// devuelven id, branchId, label, plate, code, tractor y trailer.
// Null si la unidad no existe en la empresa o esta dada de baja.

public function activeTrailers(int $companyId, array $equipmentIds): array;
// Semirremolques vigentes enganchados a cada tractor, indexados por tractor.
```

El consumidor nunca ve el modelo de `equipos`. El `scope` de empresa se aplica dentro del
adaptador: una patente que pertenece a otra empresa se responde como `null`, no como un
error.

**`activeTrailers` es lo que permite que el formulario de viaje preseleccione el
acoplado.** La relación tractor-acoplado es temporal (`equipo_relaciones.hasta IS NULL`): un
semirremolque no puede estar enganchado a dos tractores a la vez, pero un tractor puede
llevar varios (bitrén). La ficha de equipo deja crear la relación desde cualquiera de las
dos partes, así que el adaptador devuelve el semirremolque tanto si el tractor es el
equipo principal como si es el relacionado, y descarta la parte que no sea semirremolque.

**El rol físico de la unidad** (tractor o acoplado) se deduce hoy del nombre del tipo de
equipo. Es una regla de negocio sobre texto libre: renombrar un tipo la cambia en silencio.
Vive en el contexto que es dueño del catálogo, pero la forma definitiva es un atributo
explícito en `tipos_equipo`, con su carga de datos y su pantalla. Queda anotado como deuda
en §6.

### 4.1.1 `EmployeeDirectoryLookup` — responde Employees

```php
public function driver(int $companyId, int $employeeId): ?array;
// devuelve id, label y active. Null si la persona no existe o fue dada de baja.
```

Lo consume Viajes al asignar el chofer y al imputar otros haberes.

### 4.2 `TripReadingRecorder` — responde Measurement (ya implementado)

```php
public function record(ActorContext $actor, int $equipmentId, DateTimeImmutable $at, int $kilometers, string $reference): void;
```

El adaptador existente (`CodeIgniterTripReadingRecorder`) no escribe en `lecturas_equipo`:
delega en el caso de uso `RegisterReadingHandler` de Measurement, con origen `VIAJE`. Es el
comportamiento que la especificación exige en §5.13.

### 4.3 `NeumaticReadingRecorder` — responde Measurement

```php
public function record(ActorContext $actor, int $equipmentId, DateTimeImmutable $at, int $kilometers, string $reference): void;
```

**Dependencia declarada:** `EquipmentReading` hoy reconoce `MANUAL`, `ORDEN_TRABAJO`,
`IMPORTACION`, `CARGA_RAPIDA`, `ALTA_INICIAL`, `QR_ANONIMO` y `VIAJE`. No tiene un origen
para eventos de neumático. Hay que agregar `NEUMATICO` a ese dominio **antes** de
implementar el adaptador, y esa adición es un cambio de dominio, no de infraestructura.

### 4.4 `OperationalCostByEquipmentQuery` — responde Viajes

```php
public function byEquipment(int $companyId, int $equipmentId, DateTimeImmutable $from, DateTimeImmutable $to): ?array;
// fuel, expenses, driverSettlement, total, kilometers, costPerKilometer, trips
```

Es costo **operativo**, no de mantenimiento: no entra en el `costo_total` de una orden de
trabajo. Se informa por separado. `costPerKilometer` es `null` sin kilómetros válidos, nunca
`0` inventado.

### 4.5 `GomeriaOrderLauncher` — responde WorkOrders

```php
public function launch(LaunchGomeriaOrderCommand $command): int;
// devuelve el id de la orden creada
```

`LaunchGomeriaOrderCommand` lleva empresa, sucursal, equipo, neumático, motivo, kilometraje,
fecha y el empleado que reporta. El adaptador debe abrir una orden normal con origen
`GOMERIA` y tipo de servicio de gomería, no un circuito paralelo.

### 4.6 `PreventiveNeumaticEventPublisher` — responde PreventiveMaintenance

```php
public function placed(int $companyId, int $equipmentId, int $neumaticId, DateTimeImmutable $at, int $kilometers): void;
public function removed(int $companyId, int $equipmentId, int $neumaticId, DateTimeImmutable $at, int $kilometers): void;
public function retreaded(int $companyId, int $neumaticId, DateTimeImmutable $at): void;
```

Rotación y recapado **se planifican**: el plan preventivo decide cuándo actuar, el preventivo
no inventa una orden. Por eso se publica el hecho y no se abre la orden.

### 4.7 `ArcaGateway` — responde la autoridad de aplicación

```php
public function authorize(ComprobanteParaAutorizar $comprobante): AutorizacionResult;
public function status(string $cae): AutorizacionResult;
public function cancel(ComprobanteParaAnular $comprobante): AutorizacionResult;
```

Un rechazo **no es una excepción**: es un `AutorizacionResult` con `aprobado = false`, código y
mensaje, porque hay que mostrarlo al operador y guardarlo en la traza. Solo una falla de
transporte o de certificado es excepción, y en ese caso la petición queda para reintento.

Los importes viajan ya calculados: el cálculo de netos es del contexto y ARCA no recalcula.

---

## 5. Lo que falta implementar

| Adaptador | Contexto que lo aporta | Bloqueado por |
|---|---|---|
| `NeumaticReadingRecorder` → `RegisterReadingHandler` | Measurement | D7, y agregar origen `NEUMATICO` al dominio |
| `OperationalCostByEquipmentQuery` → read model de viajes | Viajes | D5 (una o cuatro empresas) |
| `GomeriaOrderLauncher` → caso de uso de órdenes | WorkOrders | Fase 3 |
| `PreventiveNeumaticEventPublisher` → plan preventivo | PreventiveMaintenance | Fase 3 |
| `ArcaGateway` → WSAA/WSFEv1 | Facturación | **D1 (certificado ARCA)** |

---

## 6. Deuda que este registro deja registrada

### 6.1 Resuelta: el acoplamiento de tablas de Viajes

`CodeIgniterTripFleetLookup` (infraestructura de Viajes) consultaba las tablas `equipos` y
`empleados` directamente. Funcionaba y estaba probado, pero ataba el contexto de Viajes al
esquema de otros dos contextos: un cambio de columna en `equipos` habría roto el adaptador
sin que ningún test lo señalara.

**Resuelto el 24/10/2026.** Ahora:

- `app/Application/Assets/Port/EquipmentFleetLookup.php` es el puerto que responde por el
  equipo, con su implementación `CodeIgniterEquipmentFleetLookup`.
- `app/Application/Employees/Port/EmployeeDirectoryLookup.php` responde por el chofer, con su
  implementación `CodeIgniterEmployeeDirectoryLookup`.
- `CodeIgniterTripFleetLookup` traduce el resultado al formato que espera el caso de uso de
  Viajes y no toca ninguna tabla que no sea de Viajes.

El cableado está en `app/Config/Services.php`, en `createTrip` y `addTripOtherEarning`.

### 6.2 Abierta: el rol físico de la unidad se deduce de texto libre

El rol tractor o acoplado se deduce del **nombre del tipo de equipo**, con una expresión
regular sobre el texto del catálogo:

```php
preg_match('/acoplado|semirremolque|remolque|batea/', $tipo)
preg_match('/camion|tractor|chasis|cabina|utilitario|pickup/', $tipo)
```

Es una regla de negocio sobre un dato que puede cambiar de nombre. Renombrar "Semirremolque" a
"Remolque sider" cambia la clasificación en silencio: el equipo deja de poder asignarse como
acoplado y el viaje no se puede cerrar con esa asignación.

Ahora vive en el contexto que es dueño del catálogo, que es donde corresponde, pero la forma
definitiva es un **atributo explícito en `tipos_equipo`** (`es_tractor`, `es_acoplado`), con su
carga de datos para el padrón existente y su pantalla de catálogo.

**Pendiente.** No se hizo en este corte porque implica una migración con carga de datos sobre
las unidades reales, y el padrón definitivo de flota todavía no está confirmado (D7).
