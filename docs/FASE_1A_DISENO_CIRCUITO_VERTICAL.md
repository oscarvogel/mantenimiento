# FASE 1A - Diseño del circuito vertical mínimo

Fecha: **7 de agosto de 2026**.

Estado: **diseño técnico listo para validación**. Este documento no autoriza el
refactor multiempresa pendiente ni incluye despliegues en Ferozo.

## 1. Objetivo

Construir el primer recorrido completo que demuestre el corazón del producto:

```text
Crear equipo
  -> registrar lectura
  -> asignar plan preventivo
  -> detectar vencimiento
  -> materializar aviso preventivo
  -> aprobar aviso y generar orden preventiva
  -> iniciar y cerrar la orden
  -> registrar lectura de salida
  -> recalcular el próximo servicio
```

El resultado debe funcionar mediante interfaz web, conservar el historial y
probar las reglas críticas sin depender de Ferozo.

## 2. Alcance del primer corte

Incluye:

- un catálogo mínimo de tipos de equipo;
- un catálogo mínimo de tipos de servicio y tareas;
- alta y consulta de un equipo;
- carga manual de kilometraje y/o horómetro;
- asignación manual de un plan preventivo;
- evaluación pura de `SIN_DATOS`, `AL_DIA`, `PROXIMO` y `VENCIDO`;
- materialización idempotente de un aviso para un vencimiento;
- generación manual y autorizada de una OT desde el aviso preventivo;
- inicio y cierre de la OT preventiva;
- actualización transaccional de lectura, equipo, plan y orden;
- scoping por empresa y sucursal, permisos y CSRF;
- pruebas unitarias, de aplicación, MariaDB y HTTP del circuito.

No incluye todavía:

- importaciones, QR, adjuntos o relaciones tractor-acoplado;
- plantillas aplicadas en lote;
- solicitudes correctivas, agrupación o triage;
- repuestos, proveedores, garantías, costos avanzados o PDF;
- alertas por correo;
- CQRS, Event Sourcing, brokers o microservicios.

## 3. Evidencia del estado actual

El checkout tiene ocho migraciones aplicadas: empresas, sucursales, usuarios,
roles, permisos y sus tablas de relación. No existen tablas, modelos, rutas ni
pantallas de equipos, lecturas, planes u órdenes.

La autenticación actual guarda `usuario_id` y un único `empresa_id` en sesión y
carga permisos y sucursales. Se confirmó que cada usuario común pertenece a una
sola empresa y que el Superadministrador es global con `empresa_id` nulo. Aún
falta representar esa capacidad y aplicar los controles que deben preceder a
los módulos de negocio:

- regenerar explícitamente la sesión después del login;
- cambiar logout de `GET` a `POST`;
- autorizar cada acción mediante permiso, no solo comprobar autenticación;
- resolver y verificar el alcance de empresa/sucursal en el servidor;
- impedir que IDs enviados por el navegador permitan acceder a otra empresa;
- resolver las decisiones pendientes de `PENDING_REFACTOR_MULTITENANCY.md`.

## 4. Mapa de bounded contexts

### 4.1 Registro de activos

Es dueño de `Equipo`: identidad, empresa, sucursal, tipo, código, patente,
estado y valores actuales de uso. Publica una vista mínima del equipo a los
demás contextos; no expone un modelo ORM compartido.

### 4.2 Medición de uso

Es dueño del historial `LecturaEquipo`. Una lectura válida actualiza la
proyección `km_actual`/`horas_actuales` del equipo dentro de la misma
transacción. Publica valores actuales válidos a Preventivo y Órdenes.

### 4.3 Mantenimiento preventivo

Es dueño de `PlanMantenimiento` y del evaluador de vencimiento. Consume una
lectura actual y un reloj, pero el evaluador puro no consulta DB ni sesión.

### 4.4 Ejecución de mantenimiento

Es dueño de `OrdenTrabajo`, sus tareas y transiciones. Recibe un `AvisoPlan`
aprobado: detectar un vencimiento no crea una orden automáticamente. El cierre
preventivo es un caso de uso coordinador porque afecta orden, lectura, equipo y
plan.

### 4.5 Contratos entre contextos

- Los contextos se referencian por ID y DTO; no comparten entidades ni builders.
- `RegistrarLectura` devuelve una instantánea de uso válida del equipo.
- `EvaluarPlan` recibe plan, instantánea de uso y fecha de evaluación.
- `MaterializarAvisoPlan` conserva una clave idempotente del ciclo evaluado.
- `GenerarOrdenPreventiva` recibe la identidad del aviso y del plan y copia los
  datos de ingreso necesarios para preservar historia.
- `CerrarOrdenPreventiva` usa una única unidad de trabajo MariaDB. No se usan
  eventos asíncronos porque el cierre exige consistencia inmediata.

## 5. Modelo mínimo

### 5.1 Agregados y entidades

| Concepto | Tipo | Invariantes mínimas |
|---|---|---|
| `Equipo` | Aggregate root | Código único por empresa; pertenece a una sucursal de la misma empresa; un equipo inactivo conserva historial. |
| `LecturaEquipo` | Entidad histórica | Informa km, horas o ambos; valores no negativos; no retrocede sin permiso y motivo. |
| `PlanMantenimiento` | Aggregate root | Tiene al menos un intervalo positivo; cada intervalo exige base y próximo valor coherentes; pertenece al mismo equipo/empresa. |
| `AvisoPlan` | Aggregate root | Representa una evaluación próxima/vencida de un ciclo; solo puede convertirse una vez en OT. |
| `OrdenTrabajo` | Aggregate root | Número único por empresa; transiciones válidas; una orden finalizada no se edita normalmente. |
| `OrdenTarea` | Entidad de la orden | Para cerrar debe existir al menos un trabajo realizado. |

Value objects sugeridos solo cuando empiece la implementación:

- `Kilometraje` y `HorasUso`: enteros no negativos;
- `IntervaloPlan`: criterios y anticipaciones válidos;
- `EstadoPlan`: `SIN_DATOS`, `AL_DIA`, `PROXIMO`, `VENCIDO`;
- `NumeroOrden`: formato e identidad por empresa;
- `ContextoActor`: usuario, empresa, sucursales y permisos autorizados.

No se crearán value objects para cada campo CRUD. Se usarán donde protejan una
invariante o eviten unidades ambiguas.

## 6. Regla de vencimiento

El estado se calcula, no se guarda como fuente de verdad.

Para cada criterio configurado:

```text
vencido   := actual >= próximo
próximo   := actual >= próximo - anticipación
sin datos := el criterio necesita un valor actual inexistente
```

Precedencia global:

```text
si falta un dato requerido                   -> SIN_DATOS
si cualquier criterio está vencido           -> VENCIDO
si cualquier criterio está en anticipación   -> PROXIMO
en otro caso                                  -> AL_DIA
```

Los criterios no configurados se ignoran. Si hay fecha y kilómetros, alcanzar
cualquiera produce `VENCIDO`; nunca se espera a que ambos venzan.

Al crear el plan desde este circuito, las bases iniciales serán la lectura
actual válida y la fecha de asignación. Al cerrar una OT preventiva:

```text
base_km       = km_salida, si aplica
base_horas    = horas_salida, si aplica
base_fecha    = fecha_finalización, si aplica
proximo_km    = base_km + intervalo_km
proximas_horas= base_horas + intervalo_horas
proxima_fecha = base_fecha + intervalo_dias
```

## 7. Persistencia propuesta

### Catálogos globales

- `tipos_equipo`
- `tipos_servicio`
- `tareas_mantenimiento`
- `tipo_servicio_tareas`

### Datos de negocio con `empresa_id NOT NULL`

- `equipos`
- `lecturas_equipo`
- `planes_mantenimiento`
- `avisos_plan`
- `ordenes_trabajo`
- `orden_tareas`
- `orden_estado_historial`
- `orden_numeradores`

Cada tabla histórica incluye timestamps, autor cuando corresponda y estrategia
de conservación. No se harán borrados físicos de equipos, lecturas, planes ni
órdenes.

Restricciones relevantes:

- `equipos`: `UNIQUE (empresa_id, codigo)`;
- una sucursal y un equipo referenciados deben pertenecer a la misma empresa;
- `lecturas_equipo` conserva `empresa_id`, `equipo_id`, fecha, valores, origen,
  usuario, motivo de corrección y anulación;
- `planes_mantenimiento` conserva las bases y próximos valores materializados;
- `avisos_plan` conserva una clave única del ciclo, estado de gestión y OT
  resultante para evitar avisos u órdenes duplicadas;
- `ordenes_trabajo`: `UNIQUE (empresa_id, numero)` e índice por plan/estado;
- `orden_numeradores`: `UNIQUE (empresa_id, anio)` y bloqueo de fila al reservar;
- las relaciones sensibles deben impedir referencias cruzadas entre empresas,
  mediante claves compuestas donde sea viable y validación de aplicación siempre.

Las migraciones nuevas serán incrementales. No se editarán las ocho ya
aplicadas; cualquier corrección a ellas se realizará mediante una migración
posterior reproducible.

## 8. Casos de uso y puertos

| Caso de uso | Permiso | Resultado |
|---|---|---|
| `CrearEquipo` | `equipos.editar` | Equipo activo dentro de empresa/sucursal autorizada. |
| `RegistrarLectura` | `lecturas.cargar` | Lectura histórica e instantánea actual actualizadas en una transacción. |
| `AsignarPlan` | `planes.editar` | Plan con bases y próximos vencimientos coherentes. |
| `ConsultarVencimientos` | `planes.ver` | Estados calculados y filtrados por alcance. |
| `MaterializarAvisoPlan` | proceso interno | Aviso idempotente para el ciclo próximo/vencido. |
| `GenerarOrdenPreventivaDesdeAviso` | `ordenes.editar` | OT numerada asociada al aviso y plan, sin duplicar el ciclo. |
| `IniciarOrden` | `ordenes.editar` | Transición `EMITIDA -> EN_PROCESO`. |
| `CerrarOrdenPreventiva` | `ordenes.cerrar` | Orden, tarea, lectura, equipo y plan actualizados atómicamente. |

Puertos de salida mínimos:

- repositorios por agregado, no por cada tabla;
- `UnitOfWork` para el cierre;
- `Clock` inyectable para fechas deterministas;
- `OrderNumberGenerator` con bloqueo transaccional por empresa;
- `ActorContext` generado desde autenticación, con capacidad global y empresa
  activa validadas, nunca desde campos libres del formulario.

Adaptadores:

- controladores y formularios CI4 como adapters de entrada;
- Query Builder/Models CI4 como adapters de persistencia;
- configuración de `Services` como composition root.

## 9. Transacciones críticas

### 9.1 Registrar lectura

1. Bloquear o releer el equipo dentro de la transacción.
2. Verificar empresa, sucursal, permiso y estado del equipo.
3. Validar no retroceso contra la última lectura válida.
4. Insertar la lectura histórica.
5. Actualizar `km_actual` y/o `horas_actuales` del equipo.
6. Confirmar; ante cualquier error, rollback completo.

### 9.2 Detectar vencimiento y generar OT preventiva

1. Bloquear el plan.
2. Recalcular su estado con datos actuales.
3. Materializar una sola vez el aviso del ciclo evaluado.
4. Cuando un responsable lo aprueba, bloquear aviso y plan.
5. Revalidar estado, empresa, sucursal, equipo y que el aviso no esté convertido.
6. Reservar número de OT de forma transaccional por empresa.
7. Crear y emitir la orden, copiando las tareas como snapshot histórico.
8. Vincular la orden y marcar el aviso como convertido.

### 9.3 Cerrar OT preventiva

1. Bloquear orden, equipo y plan.
2. Revalidar alcance, estado y datos de cierre.
3. Guardar trabajo realizado y lectura de salida.
4. Actualizar la proyección actual del equipo.
5. Recalcular bases y próximos valores del plan.
6. Marcar la orden `FINALIZADA` y registrar historial.
7. Confirmar todo junto; cualquier fallo hace rollback.

## 10. Superficie web mínima

Rutas propuestas, todas autenticadas y con autorización en servidor:

```text
GET  /equipos
GET  /equipos/nuevo
POST /equipos
GET  /equipos/{id}
POST /equipos/{id}/lecturas
POST /equipos/{id}/planes
GET  /mantenimiento/vencimientos
POST /mantenimiento/vencimientos/detectar   # diagnóstico manual, no flujo diario
POST /avisos-plan/{id}/ordenes
GET  /ordenes/{id}
POST /ordenes/{id}/iniciar
POST /ordenes/{id}/cerrar
```

Los IDs se resuelven siempre con `empresa_id` y sucursal autorizada. Un ID
válido de otra empresa responde 404 o 403 sin revelar datos.

## 11. Matriz de pruebas obligatoria para este circuito

### Dominio puro

- vencimiento solo por fecha, km y horas;
- combinación donde vence primero fecha o km;
- anticipación por cada criterio;
- `SIN_DATOS` cuando falta una lectura requerida;
- recálculo de próximos valores desde las bases de cierre;
- transiciones válidas e inválidas de OT.

### Aplicación con puertos falsos

- rechazo de lectura inferior sin permiso/motivo;
- lectura histórica y equipo se actualizan juntos;
- no se genera OT para plan `AL_DIA` o `SIN_DATOS`;
- no se duplica un aviso ni una OT para el mismo ciclo;
- cierre incompleto rechazado;
- cierre coordina lectura, equipo, plan y orden;
- una falla fuerza rollback completo.

### Integración MariaDB

- migraciones desde cero y `down()` controlado;
- constraints e índices multiempresa;
- número único ante dos altas concurrentes;
- bloqueo y rollback reales en el cierre;
- consultas filtradas por empresa/sucursal.

### HTTP/feature

- CSRF y autenticación;
- permisos de ver, editar, cargar y cerrar;
- usuario de empresa A no ve ni modifica recursos de empresa B;
- Superadministrador puede gestionar empresas y asignaciones globales sin ser
  confundido con un Administrador de empresa;
- sucursal no autorizada rechazada;
- recorrido feliz completo desde equipo hasta próximo servicio recalculado.

Esto cubre inicialmente los casos 1 a 13, 16 y 17 de la especificación en la
medida aplicable al circuito preventivo. Cada prueba debe indicar qué caso cubre.

## 12. Orden de implementación de la FASE 1B

1. **Precondición de seguridad:** decisiones multiempresa, sesión regenerada,
   logout POST, `ActorContext` y filtros de permiso/alcance con pruebas A/B.
2. **Walking skeleton de equipo:** catálogo mínimo, migración, caso de uso, alta,
   detalle y prueba HTTP.
3. **Lecturas:** dominio, transacción MariaDB, formulario y pruebas de retroceso.
4. **Preventivo puro:** catálogos, plan, evaluador sin framework y matriz 1-7.
5. **Aviso y generación de OT:** idempotencia, revisión, numeración, snapshots de
   tareas y protección contra duplicados.
6. **Cierre coordinado:** una transacción, lectura de salida y recálculo.
7. **Aceptación vertical:** recorrido HTTP completo en desktop y móvil.

Cada incremento debe ser pequeño, migrable y ejecutable. No se construirá el
siguiente contexto si el anterior no tiene pruebas verdes.

## 13. Decisiones que requieren confirmación

Antes de crear migraciones de negocio se debe confirmar:

1. **Superadministrador:** confirmado como cuenta con capacidad global para
   gestionar empresas y asignar usuarios. No equivale al rol Administrador.
2. **Membresía:** confirmado que cada usuario común pertenece a una sola empresa;
   el Superadministrador puede asignarlo o trasladarlo.
3. **Sucursales:** confirmado que el Administrador ve todas las sucursales de su
   empresa; los demás usuarios solo las asignadas.
4. **Número de OT:** aceptar provisionalmente `OT-AAAA-000001`, secuencial por
   empresa y año.
5. **Base inicial del plan:** permitir una base explícita de último servicio y
   usar, por defecto, la última lectura válida y la fecha de asignación.
6. **Avisos del primer hito:** materializar solo planes `VENCIDO`; `PROXIMO`
   queda visible en el panel pero todavía no genera aviso.
7. **Creación de OT preventiva:** será manual por un responsable desde el aviso;
   no automática por cron.
8. **Mediciones:** kilómetros enteros no negativos y horómetro decimal con una
   cifra; una lectura parcial conserva la otra magnitud actual.
9. **Planes:** un solo plan activo por equipo y tipo de servicio.
10. **Cierre:** podrá cerrar el técnico asignado si tiene `ordenes.cerrar`, o un
   responsable con ese permiso; costos opcionales en este primer hito.
11. **Equipo:** código obligatorio y único por empresa; patente opcional; los
    tipos determinan si controlan kilómetros, horas o ambos.

También se deberán agregar permisos separados para consultar y corregir
lecturas; `lecturas.cargar` no autorizará una corrección histórica.

Si estas decisiones se aprueban como paquete, la FASE 1B puede comenzar por el
bloque de seguridad y el alta mínima de equipos sin redefinir el esquema
después.
