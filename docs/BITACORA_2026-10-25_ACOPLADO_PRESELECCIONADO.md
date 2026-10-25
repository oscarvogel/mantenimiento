# Semirremolque del viaje: preselección y composición completa (bitrén)

**Fecha:** 25 de octubre de 2026
**Contexto:** Viajes (consumidor) sobre Registro de activos (proveedor).
**Alcance:** preselección del semirremolque en el formulario de viaje, contrato de lectura
del semirremolque enganchado y composición completa del viaje (bitrén o tridem).

---

## 1. Qué se cambió

Al elegir el camión en *Viajes → Nuevo viaje*, los semirremolques que ese camión tiene
enganchados se preseleccionan solos. Antes había que elegir el acoplado a mano, y el viaje
admitía **un solo** semirremolque.

- `EquipmentFleetLookup` (el puerto que responde Assets) suma `activeTrailers()`: devuelve
  los semirremolques vigentes de cada tractor. Contrato nuevo, documentado en
  `docs/CONTRATOS_ENTRE_CONTEXTOS.md` §4.1.
- `CodeIgniterTripFormReadModel` (infraestructura de Viajes) consume ese puerto y expone
  `linkedTrailerIds` por tractor en las opciones del formulario. Antes leía las tablas de
  otros contextos; esta parte nueva cruza el contrato, no la tabla.
- El dominio del viaje (`Trip`) admite hasta **3** semirremolques (bitrén o tridem) en
  lugar de uno, y expone `trailerIds()`.
- El comando de alta (`CreateTripCommand`) lleva la lista de semirremolques; el caso de uso
  valida y registra cada uno.
- El formulario (Vue) usa selección múltiple: se preselecciona la composición enganchada y
  el operador puede cambiarla.

**No hizo falta migración.** El semirremolque ya se guardaba como una asignación
(`viaje_operativo_asignaciones`, rol `ACOPLADO`) y esa tabla no tiene clave única por rol:
un segundo semirremolque es una fila más.

---

## 2. Cómo se asocia un semirremolque a un camión

La asociación ya existía en el modelo: `equipo_relaciones`, una relación **temporal** entre
dos equipos, de tipo `TRACTOR_ACOPLADO`, con `desde` y `hasta` (nulo = vigente).

**Dónde se hace:** ficha del equipo (*Equipos → una unidad*), pestaña **Resumen**, panel
**"Relaciones entre equipos"**: se elige el "Equipo relacionado", el tipo *Tractor-acoplado* y
la fecha desde. Permiso `equipos.editar`.

> El panel estaba en la pestaña *Mantenimiento* y nadie lo encontraba: la relación
> tractor-acoplado no es un tema de mantenimiento preventivo. Se movió al *Resumen*, que es
> donde están los datos del equipo, y hay un test que lo fija
> (`OperationsPages.test.js`, "deja las relaciones entre equipos en la pestaña Resumen").

**Reglas del dominio** (`app/Domain/Assets/EquipmentRelation.php` y
`CreateEquipmentRelationHandler`):

| Regla | Qué significa |
|---|---|
| Sólo equipos activos | Un equipo dado de baja no se puede relacionar ni relacionar. |
| La relación empieza después del alta | `desde` no puede ser anterior al alta de ninguno de los dos. |
| Un semirremolque no puede estar en dos camiones a la vez | La regla mira el equipo **relacionado**: si ya tiene una relación `TRACTOR_ACOPLADO` vigente, se rechaza. |
| Un camión puede llevar varios semirremolques | Ahí está el bitrén: no hay regla que lo impida. |

### La dirección importa (y la pantalla no lo dice)

La ficha deja relacionar desde cualquiera de las dos partes, y el modelo guarda cuál es el
principal y cuál el relacionado. La regla de incompatibilidad mira **al relacionado**, así
que:

- **Relacionando desde la ficha del camión** (camión = principal, semirremolque =
  relacionado) → el semirremolque queda atado a ese camión y no puede estar en otro. Es la
  forma canónica.
- **Relacionando desde la ficha del semirremolque** (semirremolque = principal, camión =
  relacionado) → el Truck queda como "relacionado", y el segundo semirremolque que se quiera
  colgar de ese mismo camión es rechazado con "El equipo relacionado ya posee una relación
  activa incompatible".

**Consecuencia práctica para un bitrén:** hay que colgar los dos semirremolques desde la
**ficha del camión** (dos veces "Equipo relacionado"). Si se los engancha desde la ficha de
cada caja, el segundo no se puede guardar.

La lectura de la nueva función `activeTrailers` acepta la relación en las dos direcciones,
así que la preselección funciona igual se haya armado como se haya armado.

---

## 3. Qué pasa con un bitrén

El **modelo de equipos** soporta el bitrén: un tractor con dos o más semirremolques
enganchados.

El **viaje** ahora también. `viajes_operativos` no tenía columna de acoplado: el
semirremolque se guarda como una asignación con rol `ACOPLADO` en
`viaje_operativo_asignaciones`, y esa tabla admite varias filas del mismo rol. Por eso la
composición completa no necesitó migración.

| Antes | Ahora |
|---|---|
| Un viaje con un solo `acoplado_id` | Hasta 3 semirremolques (`Trip::MAX_TRAILERS`), que es el límite físico de la composición: bitrén (2) o tridem (3). |
| El dominio rechazaba el segundo acoplado | Cada semirremolque se valida (existe, es semirremolque, es de una sucursal autorizada) y se registra. |
| El listado mostraba una sola caja | El listado muestra la composición completa; el detalle ya listaba cada asignación. |

Lo que hace ahora el formulario:

| Situación | Comportamiento |
|---|---|
| Un semirremolque enganchado | Se preselecciona solo. Debajo del combo: "Acoplado actual del camión: ...". |
| Dos o más (bitrén) | Se preselecciona **la composición completa**: "Bitrén: este camión tiene 2 semirremolques enganchados (...). Se preseleccionaron todos: el viaje registra la composición completa." El operador puede quitar o agregar cajas. |
| Ninguno | No se preselecciona nada. El combo sigue libre, como hasta ahora. |

La composición no es un dato que se infiera: es la que el operador ve y puede corregir antes
de guardar.

### Qué queda afuera (a propósito)

- Las **lecturas de kilometraje** del viaje siguen siendo del tractor, no de las cajas: el
  odómetro es del equipo que hace el recorrido. El plan de semirremolques (PR 5) contempla
  el km de enganche como dato propio de la caja; eso es otro cambio.
- La **liquidación** no depende del acoplado: la composición no cambia el flete ni el
  porcentaje del chofer.

---

## 4. Evidencia

```text
$ php vendor/bin/phpunit --no-coverage --filter 'CodeIgniterEquipmentFleetLookupTest|CodeIgniterTripFormReadModelTest|CodeIgniterTripFleetLookupTest'
OK (16 tests, 59 assertions)

$ npx vitest run tests/operations/TripOperationsCreatePage.test.js
Test Files  1 passed (1)
     Tests  7 passed (7)
```

Los tests nuevos:

- el puerto resuelve el semirremolque enganchado, en el sentido canónico y en el inverso;
- una relación finalizada (`hasta` con fecha) no cuenta como vigente;
- un camión sin cajas no aparece en el resultado;
- un semirremolque no se devuelve como semirremolque de un camión;
- el read model expone `linkedTrailerIds` y lo limita a las cajas que el operador puede ver;
- el formulario preselecciona con una caja, no elige con dos y avisa que es bitrén, y no
  preselecciona con ninguna.

El bundle de `assets/dashboard` se regeneró (`npm run build`), porque el CI falla si cambia
el source Vue sin recompilar los bundles versionados.
