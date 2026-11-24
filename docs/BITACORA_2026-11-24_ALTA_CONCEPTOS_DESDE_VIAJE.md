# Bitácora — Alta de conceptos desde el propio viaje (2026-11-24)

## Qué se implementó

El operador que carga un gasto, un haber o una etapa del diario de bordo puede
**dar de alta el concepto que necesita sin dejar la pantalla del viaje**.

Hasta ahora el catálogo `viajes_conceptos` solo se podía modificar en
`Viajes > Maestros`. Cuando el concepto no existía, el trabajo se partía en
dos: dejar el viaje, ir al maestro, crear el concepto, volver y seguir. En el
camino se perdía el contexto y el cálculo del importe (cantidad × unitario) hay
que reharlo.

El pedido llegó de Carlos Secre (TSA) por WhatsApp: *"para agregar un concepto
nuevo no puede estar en la misma parte? Así se evita salir del viaje y se
trabaja de manera más continua en el mismo sin salir y es más rápido también"*.

### Piezas

| Capa | Pieza |
| --- | --- |
| Dominio | `app/Domain/Trips/TripConcept.php` (value object con las reglas del catálogo) |
| Aplicación | `app/Application/Trips/CreateTripConcept.php` + `CreateTripConceptCommand` |
| Puerto | `app/Application/Trips/Port/TripConceptCatalog.php` |
| Infraestructura | `app/Infrastructure/Trips/CodeIgniterTripConceptCatalog.php` |
| Presentación | `POST viajes/operativos/(:num)/conceptos` → `OperationalTrips::addConcept()` (JSON) |
| Frontend | `components/TripConceptCreateModal.vue` + combos con búsqueda en el detalle del viaje |

## Decisiones

### Una sola regla de dominio para los dos caminos

El maestro de Viajes y el alta en línea validan **lo mismo**, porque la regla
vivía duplicada: `TripMasterCatalog::normalize()` repetía las categorías, las
unidades y la moneda. Ahora las dos entradas construyen un `TripConcept` y el
dominio rechaza lo que no corresponde:

- nombre obligatorio (máximo 120 caracteres);
- categoría `GASTO`, `HABER` o `AMBOS`;
- unidad `DIA`, `UNIDAD` o `FIJO`;
- importe unitario mayor o igual a cero;
- moneda de tres letras, y además disponible en el catálogo de monedas.

### Permisos

El alta en línea la habilita `viajes.operar` **o** `viajes.maestros.editar`
(`CreateTripConcept::canCreateConcept()`). Razonamiento: quien carga gastos y
haberes necesita el concepto para no abandonar el viaje a mitad de trabajo; el
permiso del maestro sigue sirviendo para administrar el catálogo completo. La
regla está en un solo lugar y la pantalla la usa para mostrar el botón
(`canCreateConcepts` en el payload del viaje).

### El viaje se valida antes de tocar el catálogo

El endpoint cuelga de un viaje (`POST viajes/operativos/(:num)/conceptos`) y el
caso de uso comprueba que ese viaje exista dentro del alcance del actor
(empresa y sucursales) antes de crear el concepto. El concepto es un dato
maestro de la empresa: no se regala nada por conocer un identificador.

### El nombre no se repite

`viajes_conceptos` tiene índice único por `(empresa_id, nombre)`. El alta en
línea compara el nombre ignorando mayúsculas y acentos y devuelve un mensaje
claro ("Tu empresa ya tiene un concepto con ese nombre") en lugar de dejar
reventar la consulta. La comparación se hace en PHP, no con `LOWER()`, para que
se comporte igual en MySQL y en SQLite.

### El combo con búsqueda, no el desplegable

Los tres selectores de concepto (gasto, haber, estadía) pasaron a
`AutocompleteSelect`: se escribe, se filtra y se elige. Es el mismo componente
que ya usan las localidades y los clientes del viaje, y es el criterio del
proyecto para los catálogos.

Cuando el concepto no existe, el combo ofrece **«Dar de alta»** y abre un
diálogo de alta rápida encima del del viaje (un `dialog` anidado, para que
quede por encima en la capa superior del navegador). El concepto nuevo queda
elegido en el momento y el importe se calcula solo.

### El diario de bordo ya no queda bloqueado

Antes, si no había conceptos de haber por día, el botón «Diario de bordo» no
se mostraba: el operador no podía cargar etapas y tenía que ir al maestro.
Ahora el botón está siempre disponible y el aviso ofrece crear el concepto de
estadía en el lugar. Desde el diario, el concepto se crea fijo como haber por
día, que es lo único que el diario sabe liquidar.

## Pruebas

- `tests/unit/Domain/Trips/TripConceptTest.php` — reglas del value object (8 casos).
- `tests/unit/Application/Trips/CreateTripConceptTest.php` — permiso, alcance
  del viaje, nombre duplicado, moneda no disponible y camino feliz (8 casos).
- `tests/database/Trips/CodeIgniterTripConceptCatalogTest.php` — alta real en
  base, búsqueda sin distinguir mayúsculas y rechazo del duplicado (4 casos).
- `frontend/tests/operations/TripOperationsDetailPage.test.js` — alta en línea
  desde el gasto (con el `fetch` verificado) y creación del concepto de
  estadía cuando todavía no hay conceptos por día.

Suite completa: PHPUnit y Vitest en verde.

## Pendientes

- Falta la verificación visual en la pantalla real (escritorio 1638×969 y móvil):
  el diálogo de alta rápida se diseñó con el mismo patrón que el de localidades
  (`LocalityCreateModal`), pero no se probó con un dedo ni con datos reales de TSA.
- La publicación sigue el flujo del proyecto: rama + PR a `main` + merge +
  deploy verificado a Ferozo.
