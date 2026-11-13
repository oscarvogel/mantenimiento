# Bitácora: pedidos de los videos de Viajes operativos (12/11/2026)

## Qué se pidió

Dos videos de WhatsApp grabados de la pantalla de `vogelconsultoria.com.ar/mantenimiento/`,
en el módulo **Viajes operativos**. Se transcribió el audio de ambos (los videos no tienen
texto que describa el pedido: el requerimiento está hablado).

Los pedidos son tres, y no son pedidos de modelo de datos: el de los videos es **operativo y de usabilidad**,
no de modelo de datos.

### Video 1 (17:39, 61 s) — el listado no se puede usar

> «Estoy en Viajes Operativos y acá me lista todos los viajes. A mí me pierde un poco que acá
> yo no sepa si este viaje terminó, sigue vigente, si el tramo ya terminó… está todo mezclado,
> todas las patentes mezcladas. Capaz estaría bueno alguna especie de filtro, por ejemplo que yo
> pueda elegir qué patente yo quiero ver, y que me liste todas las que están, en orden, desde la
> más antigua a la más nueva.»

- **P1**: filtrar el listado por **patente** (equipo).
- **P2**: **orden** configurable (más antigua → más nueva) para ver la historia de una patente.
- **P3**: poder ver de un vistazo si el viaje **terminó o sigue vigente**.

### Video 2 (17:46, 118 s) — el estado no refleja la realidad y las fechas están duplicadas

> «Estoy en esta patente, AB 499 OK. Acá me está diciendo que este viaje está en curso, pero en
> realidad este viaje yo lo acabé de cerrar… el del 24 del 10 al 2 del 11 y este del 4 del 11 al
> 11 del 11. Ya está cerrado este viaje. No sé por qué ahí afuera aparece como en curso.»

> «Acá dice regreso estimado 2 del 11 y salida real 24 del 10… cuando uno carga un viaje nuevo
> aparece tipo fecha real de salida y la fecha estimada, y después abajo uno pone la fecha real de
> inicio y del fin de ese viaje, ya sea de ida o de vuelta. Capaz que esto acá arriba se puede sacar
> nomás… o a lo sumo que uno pueda poner la fecha real de salida, pero que la fecha estimada de
> regreso ya te ponga automáticamente la misma que vos ponés cuando cierra ese tramo.»

- **P4** (defecto): un viaje con todos sus tramos cerrados **sigue figurando “En curso”**.
- **P5** (UX): la **fecha real de salida** y la **fecha estimada de regreso** del alta se
  contradicen con las **fechas de inicio/fin por tramo**. Propuesta: sacarlas del alta, o que la
  fecha estimada de regreso se complete sola al cerrar el tramo de vuelta.

## Método (para que el análisis sea auditable)

1. Los videos se extrajeron a 1 fps con ffmpeg (`imageio-ffmpeg` en WSL) y se rotaron 90° para
   leer las pantallas.
2. El audio de cada video se transcribió con `mcode-tools connector call
   connector__matrix__listen_audio`. Las citas de arriba son textuales.
3. Cada punto se contrastó contra el código ejecutable del repo, no contra la documentación.

## Estado actual verificado en el código

| Punto | Estado real | Evidencia |
|---|---|---|
| Filtro por fecha desde/hasta | Existe | `app/Application/Trips/OperationalTripFilters.php:25-27` |
| Filtro por sucursal | Existe | `OperationalTripFilters.php:34` |
| Filtro por estado | Existe (Planificado/En curso/Finalizado/Cancelado) | `OperationalTripFilters.php:35,85` |
| Búsqueda libre | Solo `numero` y `observaciones` | `app/Infrastructure/Trips/CodeIgniterOperationalTripReadModel.php:73-78` |
| **Filtro por patente** | **No existe** | `CodeIgniterOperationalTripReadModel.php:47-78` (no toca `equipos.patente`) |
| **Orden por fecha** | **Fijo, newest-first** | `CodeIgniterOperationalTripReadModel.php:56-57` (`fecha_salida_planificada DESC`) |
| Fecha real de salida (alta) | Existe, obligatoria | `frontend/src/pages/operations/TripOperationsCreatePage.vue:293-295` |
| Fecha estimada de regreso (alta) | Existe, opcional | `TripOperationsCreatePage.vue:297-299` |
| Fechas inicio/fin por tramo | Existen, opcionales | `frontend/src/pages/operations/TripOperationsDetailPage.vue:609-610` |
| Estado del viaje en el listado | Columna persistida `viajes_operativos.estado` | `CodeIgniterOperationalTripReadModel.php:100-101` |
| Cierre del viaje | Acción “Cerrar y generar lectura” | `frontend/src/pages/operations/TripOperationsDetailPage.vue:786-807` |

### Causa raíz de P4 (el “dice en curso pero lo cerré”)

El estado del viaje **no se deriva de los tramos**: es una columna que sólo cambia con la acción
“Cerrar viaje” (`app/Application/Trips/FinishTrip.php:52-54`).

Esa acción **exige** que todos los tramos tengan flete de facturación **y** de liquidación
(`FinishTrip.php:44-50` → `CodeIgniterTripLegRepository.php:85-99`), y el botón queda
**deshabilitado** con un mensaje rojo (`TripOperationsDetailPage.vue:806-807`).

En el video el tramo 1 mostraba **“Sin datos” en flete de liquidación**. Resultado: el operador
cerró los tramos, creyó cerrado el viaje, y el viaje quedó en `EN_CURSO` para siempre —con el
equipo y el chofer “ocupados” y sin lectura de regreso. No es un dato mal cargado: es un
bloqueo de datos en el lugar equivocado (el flete_missing debería bloquear la **liquidación**,
no el **cierre operativo**).

### Causa raíz de P5 (fechas duplicadas)

El alta pide `fecha_salida_real` + `fecha_regreso_estimado` a nivel viaje, y después cada tramo
tiene `fecha_inicio`/`fecha_fin`. En la pantalla del video la fecha estimada de regreso (02/11)
quedó congelada y se contradice con el regreso real (11/11). La app ya tiene el dato bueno: el
`fecha_fin` del último tramo.

## Factibilidad

Los tres son implementables. Detalle:

### P1 + P2 — Filtro por patente y orden

- **Sin migración**: la patente ya existe (`equipos.patente`) y el vínculo está en
  `viaje_operativo_asignaciones.equipo_id`. El filtro se resuelve con un `EXISTS` sobre
  asignaciones + `equipos`, en el read model.
- **Índices que ya lo soportan**: `idx_viaje_operativo_asignaciones_equipo`
  (`empresa_id, equipo_id, desde`) y `(empresa_id, patente)` en `equipos`
  (`2026-08-08-110021_CreateEquipmentTable.php:51`,
  `2026-10-19-150000_CreateOperationalTrips.php:81`).
- **Orden**: parámetro nuevo (`orden=asc|desc`) con default `desc`, aplicado sobre
  `fecha_salida_planificada` (o real si existe).
- **UX**: por preferencia del cliente, el selector de patente debería ser **combo con
  autocomplete** (no input libre), reutilizando el patrón de tractores que ya existe en la
  pantalla de alta.
- **Alcance**: `OperationalTripFilters` + `OperationalTripReadModel::list()` +
  `TripOperationsIndexPage.vue` + `OperationalTrips::index()`. Tests:
  `tests/unit/Application/Trips/ListOperationalTripsTest.php`,
  `tests/unit/Infrastructure/Trips/CodeIgniterOperationalTripReadModelTest.php`.

### P3 + P4 — Estado que refleje la realidad

Opciones, de menor a mayor intervención:

1. **Relajar el cierre**: que el flete faltante sea **advertencia** y no bloqueo del botón
   “Cerrar viaje”; el flete faltante sigue bloqueando el cálculo de liquidación y margen, que es
   donde importa. Es el arreglo de menor riesgo y ataca la causa raíz.
2. **Precargar el cierre**: el botón “Cerrar viaje” que propose `fecha_fin` del último tramo como
   fecha de regreso y el `km_actual` del equipo como odómetro, para que cerrar sea un clic.
3. **Estado derivado**: mostrar “Recorrido cerrado, falta cerrar el viaje” cuando todos los
   tramos tienen `fecha_fin` pero el viaje sigue `EN_CURSO`. Es información, no cambia la
   máquina de estados.
4. (Opcional) **Cierre automático** al cerrar el último tramo: deja el viaje `FINALIZADO` sin
   intervención. **No recomendado sin acuerdo**: `Trip::finish()` genera la lectura del equipo
   (`FinishTrip.php:55-61`) y el odómetro de regreso no siempre es el del último tramo.

`Trip::finish()` exige `EN_CURSO` y odómetro de regreso ≥ odómetro de salida
(`app/Domain/Trips/Trip.php:226-244`); un viaje ya `FINALIZADO` no se modifica (invariante de la
spec, sección 6.3). Cualquier derivación del estado debe ser **read model**, no un cambio del
estado persistido, salvo que se decida lo contrario explícitamente.

### P5 — Fechas

- **Quitar `fecha_regreso_estimado` del alta**: el campo es opcional y hoy solo genera
  contradicción. La columna `fecha_regreso_planificada` **se conserva** (la usan el detalle y la
  ocupación de equipo/chofer) y se puebla sola con el `fecha_fin` del último tramo.
- **Renombrar para que la jerarquía se entienda**: “Fecha de salida del viaje” vs. “Fecha de
  inicio del tramo”. No son el mismo dato: el primero es la salida de la unidad; el segundo, el
  hito de cada tramo.
- **En el detalle**: mostrar el regreso efectivo como `regreso real` si el viaje está cerrado, y
  si no, el último `fecha_fin` de tramo con la etiqueta “regreso estimado por último tramo”.

## Decisiones que hay que tomar (no son técnicas)

1. ¿El cierre del viaje se desbloquea aunque falte el flete de liquidación? (Recomiendo sí: el
   flete bloquea la liquidación, no el cierre operativo.)
2. ¿La fecha estimada de regreso se elimina del alta o se mantiene editable y se autocompleta?
   (Recomiendo quitarla del alta y autocompletarla al cerrar el último tramo.)
3. ¿Se agrega “historial por patente” como vista propia (equipo → viajes ordenados), o alcanza
   con el filtro + orden en el listado actual? (Recomiendo empezar por el filtro, que es más
   barato y resuelve el caso de uso.)
4. ¿Los viajes que quedaron trabados en `EN_CURSO` por el bloqueo de fletes se corrigen con una
   migración correctiva de datos, o se cierran a mano? (Requiere el dato de odómetro de regreso,
   que no siempre existe.)

## Decisiones tomadas por el cliente (12/11/2026)

| # | Decisión | Consecuencia técnica |
|---|---|---|
| 1 | **No** desbloquear el cierre por flete faltante | La máquina de estados no se toca. El defecto P4 se resuelve con información (estado derivado + prefill + aviso). |
| 2 | **Quitar** la fecha estimada de regreso del alta | Se quita del alta **y** del modal de edición, para no volver a contradecir el dato derivado. |
| 3 | Alcance de “historial por patente”: **filtro y orden**, sin vista nueva | No se agrega pantalla ni ruta nueva. |
| 4 | El cierre **manual** siempre exige **odómetro de regreso** | Se mantiene la invariante de `FinishTrip` / `Trip::finish()`. |

Con la decisión 1, los viajes que quedaron en `EN_CURSO` con todos sus tramos cerrados **no se
corrigen con una migración**: se cierran a mano desde el detalle, cargando fecha y km de regreso
(el odómetro no existe en el sistema y hay que tomarlo del equipo).

## Implementación (12/11/2026)

Sin migración de base de datos: se reutilizan `equipos.patente`,
`viaje_operativo_asignaciones.equipo_id` y `viajes_operativos.fecha_regreso_planificada`, con los
índices que ya existen (`idx_viaje_operativo_asignaciones_equipo`, `(empresa_id, patente)`).

### P1 + P2 — Filtro por patente y orden

| Capa | Cambio |
|---|---|
| Aplicación | `OperationalTripFilters`: nuevos `plate` (string) y `order` (`asc`/`desc`, default `desc`), con `isAscending()` y validación (patente ≤ 20 caracteres, se descartan `%` y `_`; orden sólo `asc`/`desc`). `toArray()` los incluye. |
| Aplicación | `ListOperationalTrips`: agrega `options.equipment` (patentes del parque) para alimentar el combo. |
| Puerto | `OperationalTripReadModel`: nuevo método `equipmentOptions(): list<string>`. |
| Infraestructura | `CodeIgniterOperationalTripReadModel`: filtro por patente vía subconsulta sobre `viaje_operativo_asignaciones` + `equipos` con `LOWER(e.patente) LIKE`; orden por `COALESCE(fecha_salida_real, fecha_salida_planificada)` asc/desc. |
| Presentación | `TripOperationsIndexPage.vue`: combo **Patente** con `AutocompleteSelect` (patrón del proyecto: sin valores libres) y select **Orden** (“Más recientes primero” / “Más antiguos primero”). |

### P3 + P4 — Estado derivado del recorrido

El estado del viaje **sigue siendo persistido**. Lo nuevo es un estado **derivado en el read
model**: un viaje `EN_CURSO` cuyos tramos tienen todos `fecha_fin` está “con recorrido cerrado”.

- `CodeIgniterOperationalTripReadModel`: cada ítem trae `tramos_cerrados`, `recorrido_cerrado` y
  `fecha_fin_ultimo`; el filtro de estado acepta el valor derivado `PENDIENTE_CIERRE`
  (“Recorrido cerrado, falta cerrar el viaje”).
- `GetOperationalTrip`: nuevo bloque `cierre()` con `recorrido_cerrado`, `cierre_pendiente`,
  `fecha_cierre_sugerida` (fecha del último tramo cerrado) y `km_cierre_sugerido` (`km_actual` del
  tractor, sólo si es mayor al km de salida). Si no hay datos, no se propone nada.
- `TripOperationsIndexPage.vue`: badge “Recorrido cerrado” y “Falta cerrar el viaje” en la columna
  Estado.
- `TripOperationsDetailPage.vue`: banner “El recorrido terminó el … pero el viaje sigue En curso…”,
  y el formulario de cierre **precompleta** fecha de regreso y km de regreso con esos valores,
  con textos de ayuda que dicen que son sugeridos y editables.

**Corrección propia detectada durante la implementación:** el conteo de tramos cerrados ignoraba los
tramos con `fecha_fin` en `NULL` (los contaba como cerrados). Se corrigió para que `NULL` cuente
como tramo **sin** cerrar.

### P5 — La fecha estimada de regreso pasa a ser dato derivado

- Se elimina el campo `fecha_regreso_estimado` del alta (`TripOperationsCreatePage.vue`) y del modal
  de edición del detalle.
- `Trip::syncPlannedReturn(?DateTimeImmutable)`: sólo aplica en `PLANIFICADO`/`EN_CURSO`, ignora
  una fecha anterior a la salida y acepta `null` para limpiar.
- `AddTripLeg` y `UpdateTripLeg` sincronizan `fecha_regreso_planificada` con la fecha de fin del
  último tramo cerrado (`TripLegRepository::lastLegEndDate()`, nueva consulta sobre el tramo de mayor
  `orden`).
- `CreateTrip` guarda el viaje sin fecha estimada y, al cargar los tramos, sincroniza el regreso
  estimado.
- `UpdateTrip` conserva el regreso estimado del viaje; si quedara anterior a la nueva salida, lo
  anula.
- En el detalle, el dato se muestra como “Regreso estimado (último tramo)”.

### Cambio de alcance en `conflicts()`

El fin del viaje en el cálculo de conflictos pasó a usar
`COALESCE(fecha_regreso_real, fecha_regreso_planificada, MAX(tramo.fecha_fin))`. Sin ese respaldo,
un viaje abierto con tramos ya fechados bloqueaba otros viajes del mismo equipo.

## Verificación

- `php vendor/bin/phpunit tests/unit/{Application,Infrastructure,Domain}/Trips` → **OK (234 tests,
  877 assertions)**.
- Suite PHP completa → **OK (1816 tests, 7198 assertions, 45 skipped, 0 fallos)**.
- Suite de frontend → **OK (85 archivos, 478 tests)**.
- `npm run build` (bundles) → OK; `ENCODING_QA=OK`.
- Lint `php -l` de todos los archivos modificados → OK.

Tests nuevos o ampliados:

| Test | Qué cubre |
|---|---|
| `tests/unit/Application/Trips/OperationalTripFiltersTest.php` (nuevo) | Filtro de patente, orden asc/desc, validación de entradas. |
| `tests/unit/Domain/Trips/TripPlannedReturnTest.php` (nuevo) | `syncPlannedReturn`: sólo estados abiertos, ignora fecha anterior a la salida, `null` limpia. |
| `tests/unit/Infrastructure/Trips/CodeIgniterOperationalTripReadModelTest.php` | Filtro por patente, orden asc/desc, estado `PENDIENTE_CIERRE`, `recorrido_cerrado`, `equipmentOptions`. |
| `tests/unit/Application/Trips/AddTripLegTest.php` | Sincronización del regreso estimado al cerrar un tramo. |
| `tests/unit/Application/Trips/GetOperationalTripTest.php` | `cierre_pendiente`, `fecha_cierre_sugerida`, `km_cierre_sugerido`. |
| `tests/unit/Application/Trips/CreateTripTest.php`, `UpdateTripTest.php` | Nueva firma sin `estimatedReturnAt`. |
| `frontend/tests/operations/TripOperationsIndexPage.test.js` | Filtro de patente, select de orden, badge de recorrido cerrado. |
| `frontend/tests/operations/TripOperationsCreatePage.test.js` | El campo de fecha estimada ya no está en el formulario. |
| `frontend/tests/operations/TripOperationsDetailPage.test.js` | Banner de recorrido cerrado y prefill del cierre. |

## Publicación (12/11/2026)

- PR **#525** `feat/viajes-filtro-patente-orden-estado-recorrido` → merge a `main` (`23a6d64e`).
- Deploy incremental a Ferozo (`scripts/deploy-ferozo.py --yes --skip-tests`; las pruebas se
  corrieron aparte, como en los releases anteriores):
  - `FROM=3a0e1114` → `TO=23a6d64e`.
  - **21 archivos runtime** (16 de `app/` + `assets/dashboard/`), **0 migraciones**, 0 borrados
    bloqueantes. El bundle anterior (`main-DFnmAARc.js`) se conserva en Ferozo; la limpieza es
    aparte con remote-prune.
  - `UPLOAD=OK`, `SMOKE_TESTS=OK` (login 200, favicon 200, `.env` 403, `app/` 403, `vendor/` 403,
    `writable/` 403, `migrate.php` 404).
  - Estado: `.deploy/production.json` → `23a6d64e`.
- Verificación posterior en producción:
  - El manifest servido referencia el bundle nuevo (`assets/main-D_1ihtuV.js`).
  - El bundle publicado contiene los textos nuevos: `Recorrido cerrado`, `Falta cerrar el viaje`,
    `Regreso estimado`; y **no** contiene `fecha_regreso_estimado` (el campo ya no existe).
  - Rutas: `/mantenimiento/login` 200; `/mantenimiento/viajes`, `/viajes/operativos` e `/inicio`
    respondieron 302 al login (auth viva, sin 500).
- Los archivos locales sin versionar del usuario (`.tmp/`, `node_modules/`, informes,
  `GUIA_CARGA_VIAJE_COMPLETO.md`, skills instaladas) se preservaron intactos: se declararon en
  `.git/info/exclude` (exclusión local, no versionada) para que el árbol quedara limpio sin mover
  ni versionar nada.

## Pendientes

- [x] Confirmar con el cliente las 4 decisiones de arriba.
- [x] Implementar P1 + P2 (filtro por patente + orden).
- [x] Implementar P3 + P4 (estado derivado + prefill del cierre).
- [x] Implementar P5 (fechas como dato derivado).
- [x] Actualizar `docs/ESPECIFICACION_VIAJES_CIRCUITO_LIQUIDACION.md` (secciones 4 y 10).
- [x] PR + merge + deploy a producción verificado.
- [ ] **Corrección de datos de los viajes hoy en `EN_CURSO` con todos sus tramos cerrados**: no se
      puede automatizar porque el odómetro de regreso no existe en el sistema. Requiere que el
      operador cierre cada viaje desde el detalle (con la fecha y el km ya precompletados).
- [ ] Verificación visual en la resolución del cliente (1638x969) y en móvil, con sesión iniciada.


