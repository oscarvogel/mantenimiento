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

## 7. Pantallas, paso 2b-1: maestros y tarjeta del portal (rama `feat/neumaticos-pantallas`)

El paso 2b se partió en tres PRs: **2b-1** maestros y portal, **2b-2** padrón, alta, ficha y
cambio de estado, **2b-3** depósito.

**Qué hace 2b-1:**

- `neumaticos/maestros`: marcas, modelos, tipos de cubierta y depósitos en pestañas, con alta en
  línea, edición en modal, activar e inactivar y búsqueda. En móvil cada registro es una
  tarjeta, sin tabla con scroll horizontal.
- La tarjeta **Neumáticos** del portal `/inicio` pasa a *Operativo* para quien tiene
  `neumaticos.ver`, y entra a los maestros: sin marcas ni depósitos no hay padrón que cargar.
  En 2b-2 la entrada pasa al padrón. El módulo tiene su navegación propia y retorno al portal.
- Caso de uso `NeumaticMasterCatalog` (`page`, `save`, `setActive`), puerto
  `NeumaticMasterRepository` y adaptador `CodeIgniterNeumaticMasterRepository`.
  `NeumaticBranchScope` suma `activeBranches()` para elegir la sucursal del depósito.

**Reglas:**

- Ver exige `neumaticos.ver`; crear, editar, activar e inactivar exigen `neumaticos.operar`.
  No se creó un permiso aparte para maestros: se registra como decisión revisable.
- El nombre es único por empresa sin distinguir mayúsculas; el del modelo, dentro de su marca.
- Un modelo necesita una marca activa de la empresa. Un depósito necesita una sucursal activa
  de la empresa **y** dentro de las sucursales del usuario; los depósitos de otra sucursal no se
  ven ni se editan.
- Un maestro no se borra: se inactiva. Una marca inactiva deja de ofrecerse para modelos y
  neumáticos nuevos; lo que ya la usa la conserva.

**Evidencia:**

- `tests/database/Neumaticos/NeumaticMastersTest.php` (12 pruebas, contra la migración real),
  `tests/feature/NeumaticMastersHttpTest.php` (anónimo, CSRF, permiso por ruta) y 3 casos en
  `GetPlatformModuleCatalogTest`. PHPUnit completo: 2332 pruebas, sin fallos.
- `TireMastersPage.test.js` (12 casos, incluye los dos defectos de la verificación). Vitest completo: 578 pruebas en 94 archivos.
- `npm run build` y `verify:build-sync`: OK.

**Verificación visual** (instancia temporal en `fasa_189`, no `mantenimiento-staging`): contenedor
con `--rm` y tope de 60 min, la misma imagen de staging, el código de la rama y una base temporal
con migraciones y `InitialSeeder`. Se abrió por un túnel SSH y se borró al terminar: contenedor,
base, vigilante, archivos y puertos en cero, verificado. Staging siguió con su deploy de `main`.

- Portal: la tarjeta **Neumáticos · Operativo** entra a los maestros.
- Escritorio: alta de marca, rechazo de `MICHELIN` duplicado, modelo con su marca, depósito con
  sucursal preseleccionada, edición en modal e inactivar/activar. Todo con su mensaje.
- Móvil (375 px): sin scroll horizontal; cada registro es una tarjeta con nombre, detalle, estado
  y acciones. MariaDB rechaza `deposito central` contra `Depósito Central` (sin distinguir
  mayúsculas ni tildes).
- **Dos defectos encontrados y corregidos en la verificación:**
  1. Los mensajes del servidor salían **dos veces**: el shell ya los muestra (`consumeFlash`, el
     error en modal) y la página los repetía en un banner. Se quitaron los banners de la página.
  2. En móvil la pestaña activa quedaba **fuera de la vista** (Depósitos, a la derecha). Ahora se
     desplaza para quedar visible al abrir y al cambiar de pestaña.

**Lección operativa:** un worktree con `vendor` compartido por junction **no sirve para
probar**: el autoloader de Composer resuelve la ruta real y carga las clases `App\` del checkout
original, no las del worktree. Los tests pasaban o fallaban contra otro código. El worktree
necesita su propia copia de `vendor`.

## 8. Pantallas, paso 2b-2: padrón, alta, ficha y cambio de estado (rama `feat/neumaticos-padron`)

**Qué hace:**

- `neumaticos`: el padrón, con filtros por texto (número, marca, modelo o medida), estado,
  sucursal y marca. Cada fila lleva a la ficha. Se muestran hasta 300; si hay más, se avisa y se
  pide afinar los filtros. La tarjeta del portal entra acá.
- Alta en un modal: número, sucursal, marca, modelo (solo los de la marca elegida), tipo de
  cubierta, medida, año, **estado inicial** (para cargar neumáticos que ya están en uso; no
  ofrece estados finales) y fecha de alta. Si el servidor rechaza el alta, el modal se reabre
  con lo cargado.
- `neumaticos/{id}`: la ficha con datos, edición (el número y la sucursal no se cambian),
  **cambio de estado** con motivo y fecha, rendimiento e historial de estados con usuario.
- Casos de uso de lectura `GetNeumaticRegistry` y `GetNeumaticDetail`, filtros
  `NeumaticRegistryFilters`, puerto `NeumaticRegistryReadModel` y su adaptador.

**Reglas:**

- La ficha ofrece **solo las transiciones que permite el dominio** (`NeumaticStatus::nextStates`):
  la pantalla no puede ofrecer un cambio que el caso de uso rechace. Quien solo consulta no
  ve transiciones; un estado final lo explica y no ofrece ninguna.
- **Corrección de catálogos (cambio en `UpdateNeumatic`):** solo se valida la referencia que
  cambia. Un neumático con una marca, un modelo o un tipo hoy inactivo los conserva al corregir
  otro dato; lo inactivo no se puede elegir para otro neumático.
- El rendimiento dice `sin datos suficientes` hasta que existan colocaciones (paso 3).
- La lectura del padrón resuelve el nombre de la sucursal (Organización) y del usuario de la
  bitácora (Identidad), solo lectura. Registrado en `CONTRATOS_ENTRE_CONTEXTOS.md`.

**Pendiente de decisión del cliente:** la ficha no muestra precio, origen ni observaciones
(la spec §5.12 los nombra). No se agregaron: el dominio no los modela y no hubo confirmación.

**Evidencia:**

- `tests/database/Neumaticos/NeumaticRegistryTest.php` (9, contra la migración real, incluye la
  regresión de catálogos inactivos) y `tests/feature/NeumaticsHttpTest.php` (6).
  PHPUnit completo: 2347 pruebas, sin fallos.
- `TireRegistryPage.test.js` (9) y `TireDetailPage.test.js` (9). Vitest completo: 596 pruebas
  en 96 archivos. `npm run build` y `verify:build-sync`: OK.
- **Verificación visual** en instancia temporal de `fasa_189`, con la imagen actual de staging,
  el código de la rama, base temporal y túnel SSH. Al terminar se verificó en cero.
  - Escritorio: portal → padrón vacío con su guía → maestros (marca y modelo) → alta con número
    en minúscula (queda `TSA 181917`) → ficha → cambio a *En reparación* con motivo. La ficha
    pasa a ofrecer *Reparado* y *Baja definitiva*.
  - Móvil (375 px): padrón filtrado por estado, ficha completa y alta repetida rechazada con el
    modal reabierto y los datos conservados. Sin scroll horizontal.
  - El reloj de la app y el del navegador coinciden (`America/Argentina/Buenos_Aires`), así que
    la fecha por defecto del cambio de estado no cae en el futuro.
- Mejora posible, no hecha: en móvil los filtros ocupan media pantalla antes de la lista.

## 9. Pantallas, paso 2b-3: depósito (rama `feat/neumaticos-deposito`)

**Qué hace:**

- `neumaticos/deposito`: depósitos activos como filtro con su cantidad de neumáticos,
  existencias distintas de cero (la negativa se marca), y los últimos 50 movimientos con signo,
  planilla o motivo, hora y usuario. Cada neumático lleva a su ficha.
- **Registrar movimiento**, sobre el caso de uso `RegisterDepositMovement` que ya existía:
  - *Entrada*: solo ofrece neumáticos que pueden ingresar (no en estado final); pide planilla.
  - *Salida*: solo ofrece los que hay en el depósito elegido, con cuántos hay; pide planilla.
  - *Ajuste*: cualquier neumático, cantidad con signo, motivo obligatorio.
  - El servidor vuelve a validar todo. Si rechaza, el formulario se reabre con lo cargado.
- Caso de uso de lectura `GetNeumaticDeposit`, puerto `NeumaticDepositReadModel` y su
  adaptador. La navegación del módulo queda: Padrón, Depósito, Maestros.

**Evidencia:**

- `tests/database/Neumaticos/NeumaticDepositScreenTest.php` (7, contra la migración real) y
  `tests/feature/NeumaticDepositsHttpTest.php` (4). PHPUnit completo: 2358 pruebas, sin fallos.
- `TireDepositPage.test.js` (10). Vitest completo: 606 pruebas en 97 archivos. `npm run build`
  y `verify:build-sync`: OK.
- `RouteResolutionTest` encontró un enlace armado con el fragmento dentro de `base_url()`
  (`neumaticos/maestros#depositos`); se corrigió armando la URL y agregando el `#` afuera.
- **Verificación visual** en instancia temporal de `fasa_189`, con la imagen de staging, base
  temporal y datos de prueba cargados por SQL. Al terminar se verificó en cero.
  - Entrada de `TSA 181917` a Central con planilla → aviso con la existencia resultante (1).
  - Salida: el selector ofrece solo `TSA 181917`, con "hay 1". Una salida de 2 se rechaza con
    "hay 1 y se quieren sacar 2", y el formulario conserva tipo, neumático, cantidad y planilla.
  - Ajuste de −1 sobre `TSA 229704` con motivo: existencia −1, marcada en rojo; el historial
    muestra −1 con el motivo y el usuario.
  - Móvil (375 px): sin scroll horizontal; el formulario entra completo y arranca en el depósito
    filtrado.

## 10. Paso 3a: colocación y extracción (rama `feat/neumaticos-colocaciones`)

**Decisiones del usuario (25/11/2026):**

1. **Posición cerrada:** eje (1 a 10), lado (izquierdo/derecho) y rueda exterior, interior o
   simple (única del lado). El botón es texto opcional y no distingue posiciones. Se guarda en
   las columnas del sistema anterior: lugar = eje, lado `I`/`D`, posición `E`/`I`/`S`.
2. **Km atrasado:** se acepta como lectura histórica, igual que los viajes.
3. **Neumático en depósito:** la colocación le da salida automática (planilla `COLOCACION`).
4. **Otra sucursal:** el neumático pasa a la sucursal del equipo.

**Qué se hizo:**

- Dominio: `NeumaticPosition` con valores cerrados, `label()` y `spotLabel()`.
- **Medición:** origen `NEUMATICO`, aceptado con `neumaticos.operar`, histórico como `VIAJE`.
  El frontend lo muestra como "Colocación de neumático". Ver `CONTRATOS_ENTRE_CONTEXTOS.md` §4.3.
- Migración `2026-11-25-150000_CreateNeumaticoColocaciones`: **una sola tabla**, una fila por
  colocación. La spec separa ubicaciones e historial; acá la ubicación actual es la fila con
  `activa = 1` (NULL después de extraer). Dos índices únicos sobre `activa` hacen cumplir en la
  base que un neumático esté en un solo lugar y que una posición tenga un solo neumático, sin
  borrar historial.
- El repositorio guarda y lee las colocaciones (solo agrega al final y cierra la activa).
- Contrato nuevo `EquipmentFleetCatalog` (Assets): equipos activos con scope de sucursal.
- Casos de uso `MountNeumatic` y `RemoveNeumatic`: una sola transacción con la lectura del
  equipo. La extracción no manda el neumático a un depósito: lo decide el operador.
- **D7 no bloquea:** se coloca sobre los equipos que ya existen en el sistema.

**Evidencia:**

- `MountAndRemoveNeumaticTest` (13), los tests de persistencia de colocaciones (ida y vuelta,
  posición ocupada rechazada por la base, posición liberada al extraer) y 2 tests nuevos de
  `RegisterReadingHandlerTest`. PHPUnit completo: 2376 pruebas, sin fallos. Vitest: 606;
  `verify:build-sync` OK.
- **MariaDB 11 de staging (`fasa_189`)**, sobre una base temporal y un contenedor descartable de
  la imagen de `mantenimiento-staging` con el código del commit `f6bd84df`. La base y la app de
  staging no se tocaron; base, contenedor y archivos temporales quedaron en cero, verificado:
  - Migraciones desde cero: OK. Tipos reales: `lugar varchar(5)`, `lado varchar(1)`,
    `posicion varchar(1)`, `activa tinyint(1)`, `km_colocacion int(11) unsigned`.
  - La base rechaza un neumático colocado dos veces a la vez, dos neumáticos en la misma
    posición y un equipo de otra empresa (FK compuesta).
  - Al extraer (`activa = NULL`) la posición queda libre y el mismo neumático se vuelve a
    colocar: el historial queda con 2 filas.
  - Rollback: la tabla desaparece; volver a migrar: OK.
  - La primera corrida se postergó porque `fasa_189` no era accesible desde la red del usuario.

## 11. Paso 3b: colocar y extraer desde la ficha (rama `feat/neumaticos-colocar-pantalla`)

**Qué hace:**

- La ficha suma la sección **Ubicación**:
  - Colocado: dónde está (equipo y posición), desde cuándo y con qué km, y el formulario para
    **extraer** (km y fecha).
  - No colocado: el formulario para **colocar** (equipo activo de las sucursales del usuario,
    eje, lado, rueda, botón opcional, km y fecha), con el aviso de que registra una lectura,
    saca el neumático del depósito y lo pasa a la sucursal del equipo.
  - Si el estado no permite colocarlo, explica por qué. Si no hay equipos activos, lo dice.
- El rendimiento nombra el equipo y la posición de cada colocación.
- El padrón dice dónde está colocado cada neumático.
- `GetNeumaticDetail` usa `EquipmentFleetLookup` (nombre del equipo, aunque esté de baja) y
  `EquipmentFleetCatalog` (equipos activos). Rutas `POST neumaticos/{id}/colocar` y
  `POST neumaticos/{id}/extraer` con `neumaticos.operar` y CSRF.

**Evidencia:**

- PHPUnit completo: 2380 pruebas, sin fallos. Vitest: 612 (5 casos nuevos en la ficha, 1 en el
  padrón). `verify:build-sync` OK.
- **Verificación de punta a punta en MariaDB real** (instancia temporal de `fasa_189` con la
  imagen de staging; al terminar, todo en cero, verificado):
  - Colocar TSA 181917 (en el depósito Central) en AB123CD, eje 2 izquierdo exterior, con
    120.500 km: Medición registró la lectura `NEUMATICO` con su referencia, el odómetro pasó
    de 120.000 a 120.500 y el depósito registró la salida automática (`COLOCACION`). Todo en
    una sola transacción.
  - **Rollback real:** un primer intento falló en Medición ("El tipo de equipo no controla
    kilometraje", porque el tipo de prueba tenía `controla_km = 0`). La base quedó con 0
    colocaciones, 0 lecturas y la existencia del depósito en 1: la salida automática se deshizo.
  - Colocar TSA 229704 en la posición ocupada: rechazado, nombrando al neumático que la ocupa,
    y el formulario conserva lo cargado.
  - Colocarla en la rueda interior con fecha de ayer y 119.000 km (menor al actual): lectura
    **histórica**, el odómetro no bajó, y el neumático **pasó de Posadas a la sucursal del
    equipo**.
  - Extraer TSA 181917 con 132.000 km: 11.500 km de rendimiento, lectura registrada, odómetro
    en 132.000, la colocación queda cerrada y el formulario de colocar vuelve a aparecer.
  - TSA 001 (en reparación) explica por qué no se puede colocar.
  - Móvil (375 px): el formulario se apila en una columna, sin scroll horizontal.

**Pregunta abierta para el cliente:** los equipos de tipo **Máquina** controlan horas y no km
(en staging hay 3). Colocar un neumático en una máquina hoy falla en Medición con "El tipo de
equipo no controla kilometraje". ¿Las máquinas llevan neumáticos que haya que seguir? Si es así,
hay que decidir si la colocación en una máquina registra horas o no registra lectura.

## 12. Despliegue a producción (Ferozo), 25/11/2026

**Qué se desplegó:** `main` en `18d3f859` (PRs #575, #577, #578, #579, #580, #581, #582 y #585),
más los cambios de Viajes que ya estaban en `main`. Script: `scripts/deploy-ferozo.py`,
incremental.

**Control previo (solo lectura por FTPS):**

- `.deploy/production.json` decía `61ecd3b9`, pero `Routes.php`, `Services.php` y
  `OperationalTrips.php` de producción coincidían con `cff7014a`: un deploy quirúrgico de Viajes
  que no actualiza ese registro.
- Se compararon los 10 archivos que el deploy sobrescribía: 9 iguales a `cff7014a` y el seeder
  igual a `811ebc77`, la versión inmediata anterior al cambio de #578. **Ningún cambio hecho a
  mano en producción** se iba a perder.

**Ejecución:**

- Primer intento: el script abortó antes de tocar producción porque busca PHP solo en
  `C:\xampp` o en el PATH de Windows, y en esta máquina PHP está en WSL.
- Se corrió la suite en WSL sobre `18d3f859` (2380 pruebas, sin fallos) y
  `npm run verify:build-sync` (OK), y se desplegó con `--skip-tests --yes`.
- Resultado: `UPLOAD=OK` (105 archivos, 26 bundles), `MIGRATIONS=OK` (las 3 de Neumáticos),
  `MIGRATE_PHP_REMOVED=OK`, `SMOKE_TESTS=OK` (login 200; `.env`, `app/`, `vendor/`, `writable/`
  403; `migrate.php` 404), `HASH_LOCAL=OK`, `DEPLOY_STATUS=OK`. `production.json` quedó en
  `18d3f859`.

**Verificación HTTP posterior:** `neumaticos`, `neumaticos/maestros`, `neumaticos/deposito` y
`neumaticos/1` responden 302 al login (existen y exigen sesión); los 22 bundles del manifest
responden 200.

**Pendiente:** revisión con sesión en producción (portal → tarjeta Neumáticos → maestros, alta y
colocación). Desde la máquina de desarrollo no se ingresa con credenciales reales.

**Lecciones:**

- Que `deploy-ferozo.py` encuentre el PHP de WSL, para no depender de `--skip-tests`.
- El deploy quirúrgico no actualiza `.deploy/production.json`: antes de un incremental conviene
  comparar por FTPS los archivos que se van a sobrescribir.
- Leer `DEPLOY_STATUS` del log: un `tail` en un pipe oculta el código de salida del script.

## 13. Qué sigue

- Revisión con sesión en producción.
- Respuestas del cliente: máquinas con neumáticos, precio/origen/observaciones en la ficha, y
  si la existencia en depósito es siempre 0 o 1.
- Mapa de posiciones del equipo: qué neumático va en cada eje, lado y rueda.
- Adaptadores de `GomeriaOrderLauncher` y `PreventiveNeumaticEventPublisher`.
