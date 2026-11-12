# El recorrido del tablero sale de los tramos del viaje

**Fecha:** 12 de noviembre de 2026
**Contexto:** Viajes (modelo operativo). Bounded context 12 de la especificación.
**Alcance:** el tablero y el listado de viajes operativos mostraban `Sin origen → Sin destino`
y `0 paradas` en los nueve viajes de la pantalla del operador.

---

## 1. Qué se vio

El operador mostró una captura del tablero de viajes operativos en la que la columna
**RECORRIDO** decía `Sin origen → Sin destino` y `0 paradas` en los nueve viajes que tenía
cargados. Para alguien que usa el sistema todos los días, un tablero que no dice por dónde fue
el viaje no sirve: es el dato que ordena la operación del día.

---

## 2. Verificación del comportamiento actual

Antes de tocar nada se relevó de dónde salía cada dato:

- **Los tramos son obligatorios al dar de alta un viaje.** `CreateTrip` exige al menos un tramo
  (`viaje_operativo_tramos`), que es el tramo comercial: lleva `origen`, `destino`, kilómetros y
  los dos fletes.
- **Las paradas son opcionales.** `viaje_operativo_paradas` es el itinerario fino (carga,
  descarga e hitos) y puede no tener filas.
- **Los dos read models armaban el recorrido solo con las paradas.**
  `CodeIgniterOperationalTripDashboardReadModel::details()` y
  `CodeIgniterOperationalTripReadModel::list()` leían únicamente `viaje_operativo_paradas`:
  `origen` era la primera parada, `destino` la última, y `paradas` el conteo. **Los tramos no se
  consultaban.**

Consecuencia: un viaje dado de alta con sus tramos, pero sin paradas registradas —el caso
normal, porque las paradas se cargan después o no se cargan— mostraba `Sin origen → Sin destino`
y `0 paradas`. El tablero mostraba la fila vacía de un dato que sí estaba en la base.

**No lo causó el cambio de moneda por flete.** Se verificó con `git diff 4ebddb1c 08517673`:
el defecto es preexistente, viene de `2f9ae574 feat(viajes): agregar tablero operativo de
viajes` y nunca se habían consultado los tramos.

---

## 3. Qué se cambió

### 3.1 Infraestructura (lectura)

**`CodeIgniterOperationalTripDashboardReadModel::details()`**

- Consulta `viaje_operativo_tramos` de los viajes pedidos, ordenado por `orden`.
- El **primer tramo** define el origen y el **último tramo** el destino.
- Se agrega el conteo `tramos`.
- Las paradas ya **no pisan** el recorrido: solo lo completan si el viaje no tiene tramos (en
  ese caso, la primera parada es el origen y la última el destino).

**`CodeIgniterOperationalTripReadModel::list()`**

- Nuevo método privado `legsFor()` que trae el primer y el último tramo y el conteo por viaje.
- `origen` y `destino` salen de los tramos; si el viaje no tiene tramos, se recurre a las
  paradas (comportamiento anterior).
- Se agrega la clave `tramos` al item del listado.

### 3.2 Aplicación

**`GetOperationalTripsDashboard::currentTrips()`** propaga `tramos` al payload del tablero. El
valor se lee con `?? 0` para que un adaptador que no lo informe no rompa el tablero.

### 3.3 Frontend

**`frontend/src/pages/operations/helpers.js`** — dos helpers nuevos:

- `tripRouteLabel(trip)`: arma `origen → destino` y devuelve **`Sin recorrido`** cuando el
  viaje no tiene ninguno (en lugar de `Sin origen → Sin destino`).
- `tripRouteCounts(trip)`: **`N tramos · M paradas`**, o `N tramos`, o `M paradas`, o
  `Sin tramos` si no hay ninguno.

**`TripOperationsDashboardPage.vue`** y **`TripOperationsIndexPage.vue`** usan los dos helpers
en lugar de la interpolación directa. El índice tenía el mismo defecto que el tablero.

### 3.4 Especificación

`docs/ESPECIFICACION_SISTEMA_MANTENIMIENTO.md` (§5.13.1) incorpora la regla: *el recorrido que
se muestra sale de los tramos; las paradas se cuentan aparte y solo completan el recorrido si el
viaje no tiene tramos; un viaje sin recorrido se informa como `Sin recorrido`*.

---

## 4. Evidencia

**PHPUnit** — `1790 pruebas, 7133 aserciones, 45 skipped, 0 fallos`.

```
wsl.exe bash -lc "cd /mnt/c/Programacion/mantenimiento && php vendor/bin/phpunit --no-coverage"
OK, but some tests were skipped!
Tests: 1790, Assertions: 7133, Skipped: 45.
```

Pruebas nuevas o actualizadas:

- `CodeIgniterOperationalTripDashboardReadModelTest::testTheRouteComesFromTheLegsEvenWithoutStops`
  — un viaje con dos tramos y ninguna parada muestra `Santa Fe → Bahía Blanca` y `2 tramos`.
- `CodeIgniterOperationalTripDashboardReadModelTest::testTheStopsCompleteTheRouteOnlyWhenThereAreNoLegs`
  — sin tramos, el recorrido sale de las paradas (primera y última).
- `CodeIgniterOperationalTripReadModelTest::testTheListRouteComesFromTheLegsEvenWithoutStops`
  — el listado muestra el recorrido del tramo aunque no haya paradas.
- `GetOperationalTripsDashboardTest` — el tablero propaga `tramos`.

**Vitest** — `474 pruebas en 85 archivos, 0 fallos`.

```
cd frontend; npx vitest run
Test Files  85 passed (85)
Tests  474 passed (474)
```

Pruebas nuevas: `tripRouteLabel` y `tripRouteCounts` en `helpers.test.js`, más los casos de
"Sin recorrido" en las páginas de tablero e índice.

**Lint PHP** — `php -l` sin errores en los seis archivos PHP tocados.

**Bundles** — `npm run build` y `npm run verify:build-sync` → `BUILD_SYNC=OK`.

---

## 5. Bugs que aparecieron durante el arreglo

Se dejan asentados porque son la clase de error que se repite en este repo:

1. **El destino se quedaba clavado en la primera parada.** La primera versión de la corrección
   asignaba origen y destino por parada solo si estaban vacíos, así que con dos paradas el
   destino quedaba en la primera. Lo detectó
   `testTheStopsCompleteTheRouteOnlyWhenThereAreNoLegs` (esperaba `Mendoza`, recibió `Córdoba`).
   Se corrigió agrupando las paradas por viaje y tomando la primera y la última.

2. **`Undefined array key "tramos"` en la aplicación.** `GetOperationalTripsDashboard` leía
   `$detail['tramos']` sin defecto y el fake del test no lo traía: tres errores. Se resolvió
   con `?? 0` en la capa de aplicación (un adaptador que no informe el conteo no debe romper el
   tablero) y actualizando el fake para que el contrato se ejercite.

3. **`Deletes are not allowed unless they contain a "where" or "like" clause`.** En CI4 4.7 el
   `delete()` sin `where` está prohibido. Además `where()` tiene la firma
   `where($key, $value, ?bool $escape)`: el operador va **en la clave**
   (`where('viaje_id >', 0)`), no como segundo argumento.

---

## 6. Publicación

- Rama: `fix/viajes-recorrido-desde-tramos`.
- Commits: `2dfe4734` (código), `87a13690` (bundles).
- PR y merge: pendientes de completar en el momento de escribir esta bitácora.

---

## 7. Pendientes

- **Evidencia visual pendiente del operador.** En este entorno no hay sesión autenticada, así
  que no se puede afirmar que el recorrido se vea bien a 1638x969 ni a 1366x768. El usuario
  tiene que confirmarlo contra la pantalla real.
- **Estado de los viajes de producción.** No se puede consultar la base de producción desde
  este entorno (Ferozo no tiene SSH ni CLI). Se infiere del dominio que los viajes tienen al
  menos un tramo, pero si alguno no lo tuviera, seguirá mostrando `Sin recorrido` — que es la
  conducta correcta.
- **Aplicar el mismo criterio a otras pantallas** que armen recorridos a mano, si las hay. En
  esta entrega se corrigieron el tablero y el índice, que eran los dos lugares con el defecto.
