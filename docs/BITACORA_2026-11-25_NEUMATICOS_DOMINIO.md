# Bitácora 25/11/2026 — Neumáticos: dominio puro

**Contexto:** Neumáticos y gomería (agente 13).
**Origen:** `docs/PLAN_AMPLIACION_NEUMATICOS_VIAJES_FACTURACION.md` §5 y especificación §5.12.
**Bloqueo vigente:** D7 (padrón definitivo de flota) sigue abierta. Este trabajo no depende de
ella: no toca equipos, tablas ni pantallas.

---

## 1. Qué se hizo

Se escribió el dominio del contexto, sin base, sin CodeIgniter y sin reloj global, en
`app/Domain/Neumaticos`:

| Clase | Qué es |
|---|---|
| `Neumatic` | Agregado raíz. Identidad por empresa + número, ciclo de vida, colocaciones y bitácora de estados. |
| `NeumaticNumber` | Número del neumático. Normalizado, obligatorio, máximo 20 caracteres (columna del sistema anterior). |
| `NeumaticStatus` | Los 11 estados del sistema anterior y sus transiciones. |
| `NeumaticStatusChange` | Entrada de la bitácora de estados (reemplaza `historial_estado_cubiertas`). |
| `NeumaticPosition` | Lugar, lado, posición y botón en el equipo. |
| `NeumaticMounting` | Una colocación, con su extracción cuando la hay. El equipo va por ID. |
| `NeumaticPerformance` | Km entre colocación y extracción, o `sin datos suficientes`. |
| `NeumaticDepositStock` | Existencia de un neumático en un depósito, con entradas y salidas por planilla. |

Pruebas: `tests/unit/Domain/Neumaticos` (40 pruebas). Suite completa: 2261 pruebas, sin fallos.

## 2. Invariantes del plan (§5.2) y dónde quedan

| # | Invariante | Dónde |
|---|---|---|
| 1 | El número no cambia | `Neumatic::$number` es `readonly`; `describe()` no lo recibe. |
| 2 | Una sola ubicación activa | `Neumatic::mount()` y `reconstitute()`. |
| 3 | Extracción no anterior a colocación | `NeumaticMounting`. |
| 4 | `km_extraccion < km_colocacion` deja el rendimiento no confiable | `NeumaticPerformance::between()`. No es excepción: se registra y se informa `sin datos suficientes`. |
| 5 | Stock no negativo sin ajuste documentado | `NeumaticDepositStock::dispatch()` / `adjust()`. |
| 6 | NUEVA no pasa directo a BAJA DEFINITIVA | `NeumaticStatus::nextStates()`. Llegan a la baja EN REPARACION, RECAPADA, VULCANIZADA y DESCARTE. |

## 3. Supuestos tomados (revisar con el cliente)

Reglas que la documentación no dice de forma explícita y se dedujeron del significado de cada
estado:

1. **Estados en que un neumático se puede colocar:** NUEVA, 1/2 USO, REPARADO, RECAPADA y
   VULCANIZADA. No se coloca uno en reparación, descartado, a la venta ni fuera de la empresa.
2. **Un neumático colocado no cambia a un estado no montable:** primero se extrae.
3. **Un neumático colocado no cambia de sucursal:** acompaña al equipo.
4. **Una colocación nueva no empieza antes de la última extracción**, y la bitácora de estados
   no retrocede en el tiempo.
5. **No se da de alta en un estado final** (BAJA DEFINITIVA, TRANSFERIDA, VENDIDA).
6. Las posiciones no se cierran a un catálogo: solo se validan los largos del sistema anterior.

## 4. Ciclo de vida: preguntas respondidas el 25/11/2026

El diagrama de §5.1 del plan estaba incompleto. Respuestas del cliente:

1. **EN REPARACION → BAJA DEFINITIVA: sí.** Un neumático sin arreglo se da de baja desde
   reparación. La invariante 6 queda acotada a su texto literal: NUEVA no pasa directo a la baja.
2. **RECAPADA o VULCANIZADA → EN REPARACION: sí.** Un neumático recapado o vulcanizado puede
   volver a repararse.
3. **NUEVA → EN REPARACION: sí**, sin pasar por 1/2 USO.
4. **1/2 USO → P/VENTA: sí.**

Transiciones vigentes:

| Desde | Hacia |
|---|---|
| NUEVA | 1/2 USO, EN REPARACION, P/VENTA |
| 1/2 USO | EN REPARACION, P/VENTA |
| EN REPARACION | REPARADO, BAJA DEFINITIVA |
| REPARADO | VULCANIZADA, RECAPADA, DESCARTE, TRANSFERIDA |
| RECAPADA, VULCANIZADA | EN REPARACION, BAJA DEFINITIVA |
| DESCARTE | BAJA DEFINITIVA |
| P/VENTA | VENDIDA |
| BAJA DEFINITIVA, TRANSFERIDA, VENDIDA | — (finales) |

Cambiar una regla es editar `NeumaticStatus::nextStates()` y su prueba.

## 5. Casos de uso (segundo PR, rama `feat/neumaticos-casos-de-uso`)

En `app/Application/Neumaticos`, probados con dobles en memoria
(`tests/_support/Neumaticos/InMemoryNeumatics.php`), sin base:

| Plan §5.5 | Caso de uso | Permiso |
|---|---|---|
| `RegistrarNeumaticoCommand` | `RegisterNeumatic` | `neumaticos.operar` |
| `ActualizarNeumaticoCommand` | `UpdateNeumatic` | `neumaticos.operar` |
| `CambiarEstadoNeumaticoCommand` | `ChangeNeumaticStatus` | `neumaticos.operar` |
| `RegistrarEntradaDepositoCommand` / `RegistrarSalidaDepositoCommand` | `RegisterDepositMovement` (entrada, salida o ajuste) | `neumaticos.operar` |
| `ObtenerRendimientoNeumaticoQuery` | `GetNeumaticPerformance` | `neumaticos.ver` (también `operar`) |

Puertos nuevos en `app/Application/Neumaticos/Port`: `NeumaticRepository`, `NeumaticCatalog`,
`NeumaticBranchScope`, `NeumaticDepositRepository`, `NeumaticsUnitOfWork` y `NeumaticClock`.
En el dominio se sumó `NeumaticDepositMovement`.

Reglas de aplicación:

- Todo acceso lleva empresa y sucursales del actor. Un neumático, sucursal o depósito de otra
  empresa responde igual que uno inexistente.
- El Superadministrador global no opera neumáticos: no tiene empresa.
- El número es único **por empresa**; otra empresa puede usar el mismo. La migración debe
  respaldarlo con un índice único `(empresa_id, numero)`.
- El modelo tiene que ser de la marca elegida, y marca, modelo y tipo de cubierta tienen que
  estar activos.
- Altas, cambios de estado y movimientos no aceptan fecha futura.
- Un neumático colocado o en estado final no ingresa a un depósito.
- Existencia y movimiento se guardan juntos: no hay saldo sin movimiento que lo explique.
- Los permisos `neumaticos.ver` y `neumaticos.operar` todavía no existen en la base: los crea
  la migración del próximo PR.

Pregunta abierta: `deposito_stock.cantidad` admite más de 1, pero cada neumático es único. ¿La
existencia de un neumático en un depósito es siempre 0 o 1? Hoy no se limita.

## 6. Persistencia (paso 2a, rama `feat/neumaticos-persistencia`)

**Migraciones:**

- `2026-11-25-100000_CreateNeumaticosTables`: `neumatico_marcas`, `neumatico_modelos`,
  `neumatico_tipos_cubierta`, `neumaticos_estados`, `neumaticos`,
  `neumatico_estado_historial`, `depositos`, `deposito_stock` y `deposito_movimientos`.
- `2026-11-25-100100_AddNeumaticosPermissions`: `neumaticos.ver` (Administrador,
  Responsable de mantenimiento, Técnico u operador, Consulta y Gerencial) y
  `neumaticos.operar` (Administrador y Responsable de mantenimiento). `InitialSeeder` asigna
  los mismos roles.

**Decisiones de esquema:**

- **Catálogos por empresa.** Marcas, modelos y tipos de cubierta llevan `empresa_id`, como
  `marcas` de equipos. Las FK compuestas `(empresa_id, id)` impiden en la base que un
  neumático use el catálogo o la sucursal de otra empresa. El puerto `NeumaticCatalog`
  recibe la empresa.
- **`neumaticos_estados` es un catálogo de referencia** con los 11 códigos del dominio. No
  guarda `estado_origen`/`estado_destino` como dice la spec §5.12: las transiciones viven en
  `NeumaticStatus` y una segunda copia en la tabla divergiría. La FK impide guardar un estado
  inexistente.
- **Número único por empresa:** índice `uq_neumaticos_empresa_numero`.
- **Sin `deleted_at` en `neumaticos`:** un neumático no se borra, se da de baja por estado.
  La bitácora y los movimientos son append-only.
- **Colocaciones diferidas al paso 3.** `neumatico_ubicaciones` y `neumatico_colocaciones`
  llegan con la colocación y la extracción, que generan lecturas del equipo. Hasta entonces
  el repositorio rechaza guardar un neumático con colocaciones en lugar de perderlas en
  silencio.
- `origen`, `precio` y `observaciones` de la spec no están todavía: el dominio no los
  modela. Se suman con la ficha del neumático (paso 2b) si el cliente los usa.
- No se cargan catálogos iniciales: son por empresa y los del sistema anterior son de una sola
  (TSA). La carga va con las pantallas de maestros.

**Adaptadores** en `app/Infrastructure/Neumaticos` y casos de uso conectados en
`Config\Services`: `registerNeumatic`, `updateNeumatic`, `changeNeumaticStatus`,
`registerNeumaticDepositMovement` y `getNeumaticPerformance`.

**Evidencia:**

- `tests/database/Neumaticos/NeumaticosPersistenceTest.php` (16 pruebas) corre **las
  migraciones reales** sobre SQLite con FK activas, no un esquema copiado a mano.
- Suite completa: 2311 pruebas, sin fallos (51 saltadas, las de MySQL ya existentes).
- **MariaDB 11 de staging (`fasa_189`, stack `mantenimiento-staging`)**, sobre una base
  temporal en el contenedor `mantenimiento-mariadb-staging` y un contenedor descartable de la
  misma imagen. La base y el contenedor de staging no se tocaron, y lo temporal se borró al
  terminar:
  - Migraciones desde cero en dos lotes (código de la imagen `bc95bf34` y luego esta rama):
    OK. Tipos reales: `numero varchar(20)`, `estado varchar(20)`,
    `anio_fabricacion smallint(4) unsigned`.
  - La base rechaza el número duplicado en la empresa, la marca de otra empresa, la sucursal
    de otra empresa y un estado inexistente. Acepta el mismo número en otra empresa.
  - `migrate:rollback` deja 0 tablas y 0 permisos de neumáticos; volver a migrar: OK.
  - `InitialSeeder` asigna `neumaticos.ver` a 5 roles y `neumaticos.operar` a 2.

## 7. Qué sigue

- Paso 2b: controlador, rutas y pantallas del padrón, la ficha, el depósito y los maestros;
  la tarjeta del portal abre el módulo.
- Adaptadores de `NeumaticReadingRecorder`, `GomeriaOrderLauncher` y
  `PreventiveNeumaticEventPublisher` (ver `CONTRATOS_ENTRE_CONTEXTOS.md` §5).
- Todo lo que resuelve en qué equipo está el neumático sigue bloqueado por D7.
