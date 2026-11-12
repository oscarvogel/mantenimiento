# Tramos con fecha de inicio y fin, y patente del semirremolque automática

**Fecha:** 12 de noviembre de 2026
**Contexto:** Viajes (modelo operativo). Bounded context 12 de la especificación.
**Alcance:** (a) fecha de inicio y de fin por tramo, visibles en la tabla *Tramos* del
detalle del viaje; (b) el semirremolque se completa solo, con su patente, al elegir el
tractor.

---

## 1. Qué pidió el operador

En el detalle de un viaje operativo, la tabla **Tramos** mostraba recorrido, toneladas,
km, flete de facturación, flete de liquidación, comisión, costos asignados y margen. Faltaba
el dato temporal: no se veía **cuándo salió y cuándo terminó cada tramo**.

Al mismo tiempo, al corregir un viaje ya iniciado (modal *Editar viaje*), cambiar el tractor
no traía el semirremolque: el operador tenía que buscarlo a mano y leer la patente de otro
lado. En el alta de viaje eso ya pasaba; faltaba en la edición y faltaba que la patente se
viera de forma explícita.

---

## 2. Qué se cambió

### 2.1 Fechas del tramo

**Dominio** — `app/Domain/Trips/TripLeg.php`

- `TripLeg` acepta `?DateTimeImmutable $startDate` y `?DateTimeImmutable $endDate`.
- La hora se descarta: el tramo se maneja **por fecha** (00:00), igual que las fechas del viaje.
- Invariante nueva: la fecha de fin **no puede ser anterior** a la de inicio. Vive en el
  dominio, no en el formulario: si el dato llega invertido, se rechaza con un mensaje claro
  en lugar de guardar un tramo imposible.

**Aplicación**

- `TripDateInput` (nuevo, `app/Application/Trips/TripDateInput.php`): parseo único de fechas
  para el contexto de Viajes. Antes ese parseo estaba duplicado dentro de
  `CreateTripCommand`; ahora ambos caminos usan la misma implementación y los mismos
  mensajes.
- `TripLegInput` y `AddTripLegCommand` (`UpdateTripLegCommand` envuelve al segundo) toman
  `fecha_inicio` y `fecha_fin` como opcionales.
- `AddTripLeg`, `UpdateTripLeg` y `CreateTrip` pasan las fechas al `TripLeg`.

**Infraestructura**

- `CodeIgniterTripLegRepository` persiste `fecha_inicio` y `fecha_fin` como `DATE`.
- `CodeIgniterOperationalTripReadModel` las devuelve en el read model del viaje.

**Migración** — `2026-11-12-090000_AddTripLegDates.php`

- Agrega `fecha_inicio` y `fecha_fin` (`DATE`, null) a `viaje_operativo_tramos`.
- `up()` es idempotente: comprueba la existencia de cada columna antes de agregarla.
  Para eso invalida la caché de nombres de columna de Forge
  (`$this->db->resetDataCache()`), porque Forge cachea el estado de la tabla y una segunda
  corrida intentaba agregar la columna de nuevo.
- `down()` **se niega a correr** si hay tramos con fechas cargadas: borrar la columna
  perdería el dato. Es el mismo criterio que usa
  `2026-11-11-090000_MakeOperationalTripLegAmountsOptional`.

**Presentación**

- La tabla *Tramos* suma la columna **Fechas**: `20/10/2026 → 21/10/2026`, o `Desde …` /
  `Hasta …` cuando hay una sola, o `—` cuando el tramo no tiene fechas.
- El formulario *Agregar tramo* y el modal *Editar tramo* suman los campos
  *Fecha de inicio* y *Fecha de fin* (opcionales).
- El alta de viaje (`Tramos de flete`) también los suma: un tramo se puede fechar desde el
  mismo momento en que se carga.

### 2.2 Patente del semirremolque automática

- En el modal *Editar viaje*, al cambiar el tractor, el semirremolque se completa con el que
  ese camión tiene enganchado (`linkedTrailerIds`), y bajo el campo se lee
  *Patente del semirremolque: AC222AC* (o la lista de patentes en bitrén).
- Si el viaje todavía no tiene semirremolque, al abrir el modal se completa con el del
  tractor. Si ya tiene uno, **no se toca**: el dato del viaje manda.
- En el alta de viaje, el texto de ayuda pasó de *Acoplado actual del camión* a
  *Patente del semirremolque*, y se agregó una tarjeta **Semirremolque** que muestra la
  patente de la composición elegida, junto a las tarjetas de Empresa, Dominio y Nro. de viaje.

El dato no es nuevo: `linkedTrailerIds` ya lo producía
`CodeIgniterTripFormReadModel` y el alta de viaje ya lo usaba. Lo que faltaba era
**consumirlo en la edición** y **mostrar la patente de forma explícita**.

---

## 3. Decisiones

| Decisión | Por qué |
|---|---|
| Las fechas son **opcionales** | Los tramos ya cargados no tienen fechas y no se inventa historia. Quedan en `—` hasta que el operador las cargue. No hay backfill. |
| Una sola columna **Fechas** en vez de dos | La tabla ya tiene 10 columnas y hace scroll horizontal. Una columna con `inicio → fin` se lee de un vistazo y no ensancha la tabla. |
| El rango se valida en el dominio | La regla "el fin no puede ser anterior al inicio" es de negocio, no de formulario: el mismo control tiene que aplicar al alta, a la edición y a cualquier carga futura. |
| Sin contrato nuevo entre contextos | El tramo es del contexto Viajes y sus fechas no se cruzan con otro contexto. No se tocó `docs/CONTRATOS_ENTRE_CONTEXTOS.md`. |
| `down()` bloqueado con datos | Preferimos un rollback que avise a uno que borre fechas ya cargadas. |

---

## 4. Qué se tocó

```
app/Domain/Trips/TripLeg.php                                  (fechas + invariante)
app/Application/Trips/TripDateInput.php                       (nuevo)
app/Application/Trips/TripLegInput.php
app/Application/Trips/AddTripLegCommand.php
app/Application/Trips/AddTripLeg.php
app/Application/Trips/UpdateTripLeg.php
app/Application/Trips/CreateTrip.php
app/Application/Trips/CreateTripCommand.php                   (parseo de fechas unificado)
app/Infrastructure/Trips/CodeIgniterTripLegRepository.php
app/Infrastructure/Trips/CodeIgniterOperationalTripReadModel.php
app/Database/Migrations/2026-11-12-090000_AddTripLegDates.php (nuevo)
frontend/src/pages/operations/TripOperationsDetailPage.vue
frontend/src/pages/operations/TripOperationsCreatePage.vue
```

---

## 5. Pruebas

| Suite | Resultado |
|---|---|
| PHPUnit (WSL, PHP 8.5.4, SQLite) | **1763 pruebas, 0 fallos** (45 omitidas por falta de extensiones) |
| Vitest (frontend) | **466 pruebas, 0 fallos** en 85 archivos |
| MariaDB 11 descartable (`fasa_189`, base `mantenimiento_verify`) | migración nueva aplicada, revertida y reaplicada; columnas `fecha_inicio`/`fecha_fin` created como `date` nullable |
| `php -l` sobre los 16 archivos PHP tocados | sin errores de sintaxis |

### 5.1 Hallazgo: la cadena de migraciones desde cero ya estaba rota (preexistente)

Al correr la cadena completa sobre una base MariaDB 11 vacía, la corrida se corta en
`2026-11-06-100000_AddPrincipalFlagToLocalities`, con un error de sintaxis en el `UPDATE` que
marca las localidades de Brasil:

```
You have an error in your SQL syntax ... near ')
AND `codigo_fuente` IN ('1100205','1200401',...)' at line 2
```

**No es culpa de este cambio.** Se comprobó con una corrida de control: la misma cadena, con
el mismo código y **sin** la migración nueva, falla en el mismo punto y con el mismo error
(última versión aplicada en ambos casos: `2026-11-05-130000`).

El origen probable es `markBrazil()` de esa migración: arma
`whereIn('provincia_id', $provinceIds)` con la lista de provincias de Brasil y, cuando esa
lista llega vacía o con un elemento nulo, el `IN ()` queda mal formado.

**Consecuencia práctica:** `php spark migrate --all` sobre una base vacía no llega a la
migración nueva. En producción nomolesto, porque la base ya está migrada y la migración
`2026-11-12-090000` corre sola sobre el esquema existente; pero **una instalación desde
cero está bloqueada** hasta que se corrija esa migración. Queda como pendiente para el
contexto de Geografía, no para Viajes.

Para verificar la migración nueva contra MariaDB se aplicó sobre la base descartable
marcando como aplicadas las versiones posteriores a la que corta la cadena, y luego se
corrieron `up()`, `down()` y `up()` otra vez: las columnas se crean, se borran y se vuelven
a crear sin errores.


Pruebas nuevas:

- `tests/unit/Domain/Trips/TripLegTest.php`: fechas sin hora, tramo sin fechas, rechazo de
  fecha de fin anterior a la de inicio.
- `tests/unit/Application/Trips/AddTripLegTest.php`: parsing de `fecha_inicio`/`fecha_fin`,
  rechazo de fecha inválida, tramo guardado con sus fechas.
- `tests/unit/Infrastructure/Trips/CodeIgniterTripLegRepositoryTest.php`: persistencia de las
  fechas en alta y en edición.
- `tests/unit/Infrastructure/Trips/CodeIgniterOperationalTripReadModelTest.php`: el read model
  devuelve las fechas del tramo.
- `tests/database/AddTripLegDatesMigrationTest.php`: `up()` agrega columnas nullables y es
  idempotente, `down()` borra las columnas y se niega a correr con fechas cargadas.
- `frontend/tests/operations/TripOperationsDetailPage.test.js`: columna *Fechas* con rango,
  campos de fecha en alta y edición, patente del semirremolque y autocompletado al cambiar el
  tractor.
- `frontend/tests/operations/TripOperationsCreatePage.test.js`: tarjeta de semirremolque con
  patente y campos de fecha por tramo.

---

## 6. Pendientes

1. **Publicación.** El cambio sigue el flujo del proyecto: rama → PR a `main` → merge →
   deploy. Los bundles de `assets/dashboard/` se reconstruyen y se suben con el source
   (`npm run build` en `frontend/`); el build es reproducible y `npm run verify:build-sync`
   avisa si quedó drift.
2. **Producción (Ferozo).** Subir el release por FTPS y correr la migración con
   `scripts/migrate-remote.php` (subido como `migrate.php`, invocado por HTTP con
   `X-Migrate-Token`, y borrado al terminar). No hay `php spark` ni shell en Ferozo.
3. **Datos existentes.** Los tramos ya cargados quedan sin fechas. Si el operador quiere
   fecharlos, se cargan desde *Editar tramo*; no hay carga masiva.
4. **Verificación visual.** Falta la captura de escritorio (1638x969) y de móvil (1366x768) de
   la tabla con la columna *Fechas* en el navegador, contra datos reales. La evidencia
   automatizada no alcanza para dar por aceptada la pantalla.
5. **Instalación desde cero.** La cadena de migraciones se corta en
   `2026-11-06-100000_AddPrincipalFlagToLocalities` (ver §5.1). Es un pendiente del contexto
   de Geografía y hay que resolverlo antes de que una instalación limpia pueda terminarse.
