# El flete de liquidación se carga en su propia moneda

**Fecha:** 12 de noviembre de 2026
**Contexto:** Viajes (modelo operativo). Bounded context 12 de la especificación.
**Alcance:** el flete de liquidación de un tramo puede cargarse en una moneda distinta a la
del flete de facturación, para que el operador no convierta a mano.

---

## 1. Qué pidió el operador

Al cargar un viaje, el operador elige la moneda del tramo (peso, real o dólar) y carga **los
dos fletes en esa misma moneda**. El flete de liquidación es la base del porcentaje del
chofer, y en la operatoria ese importe ya se conoce **en pesos**: hay que buscar la
cotización y hacer la conversión a mano para poder informarlo.

El caso concreto: un viaje con flete de facturación de 4.700 dólares y flete de liquidación
de 5.875.000 pesos para el chofer. Como el sistema tenía una sola moneda por tramo, el
operador tuvo que convertir los 5.875.000 pesos a dólares para poder informarlos, con el
riesgo de que el importe quedara mal.

El pedido fue twofold: **que la liquidación se pueda cargar en pesos aunque la facturación
esté en otra moneda**, y **verificar cómo funciona hoy** antes de cambiarlo.

---

## 2. Verificación del comportamiento actual

Se relevó el circuito completo antes de tocar nada:

- **Una sola moneda y una sola cotización por tramo.** `viaje_operativo_tramos` tiene
  `moneda` y `cotizacion`, y los dos fletes se informan en esa moneda. El dominio
  (`TripLeg`) tenía una única `currency`/`exchangeRate` para el tramo, y calculaba
  `invoiceAmountArs()` y `liquidationAmountArs()` con esa misma cotización.
- **Los importes se duplicaban en pesos.** `flete_facturacion` (importe original) y
  `flete_facturacion_ars` (convertido); lo mismo con el de liquidación. Todos los
  consumidores (detalle del viaje, panel, cierre del viaje, resumen y liquidación del
  chofer en PDF) leen las columnas **en pesos**.
- **El catálogo de monedas ya existía** (`viajes_monedas`: ARS, BOB, BRL, CLP, COP, EUR,
  GYD, PEN, PYG, SRD, USD, UYU, VES) y se validaba contra la tabla, pero la validación era
  de una sola moneda por tramo.
- **Los tramos existentes no se tocan.** El backfill deja la liquidación con la misma
  moneda y cotización que la facturación: el resultado es indistinguible del de hoy.

Conclusión: el sistema ya sabía separar el **importe original** del **importe en pesos**
(`flete_liquidacion` y `flete_liquidacion_ars`). Lo que no existía era la moneda propia
del segundo flete. El cambio va sobre esa base, sin tocar los consumidores: siguen
leyendo pesos.

---

## 3. Qué se cambió

### 3.1 Dominio

**`app/Domain/Trips/TripLegAmount.php` (nuevo)** — value object con el importe, su moneda y
su cotización a pesos. El importe puede no estar cargado (los fletes son opcionales
mientras el viaje está abierto), la moneda y la cotización siempre existen.

Se siguió el patrón del `TripMoney` que ya usa el contexto para combustible, gastos y
haberes, pero en versión **anulable**: `TripMoney` exige importe y se usa en costos, que
siempre se informan; los fletes no. No se tocó `TripMoney` para no debilitar la garantía
de los costos.

**`app/Domain/Trips/TripLeg.php`** — ahora recibe dos `TripLegAmount`: uno para el flete
de facturación y otro para el de liquidación. La moneda y la cotización del tramo se
fue ron a cada importe: es lo que hace que cada flete pueda ir en su moneda. La comisión
del chofer sigue calculándose sobre la liquidación **convertida a pesos**, que es lo que
se liquida.

### 3.2 Aplicación

- `AddTripLegCommand` y `TripLegInput` suman `moneda_liquidacion` y
  `cotizacion_liquidacion`. Vacia significa "hereda la de facturación".
- La resolución de la moneda de liquidación vive en el comando, no en el formulario: si
  no se informa moneda, hereda la de facturación; si es ARS, la cotización es 1 y no hace
  falta informar nada; si es una moneda distinta, hay que informar la cotización o el sistema
  avisa con un mensaje claro. **Nunca** se reutiliza la cotización de una moneda para
  otra: esa era exactamente la fuente del error que el operador reportaba.
- `AddTripLeg`, `UpdateTripLeg` y `CreateTrip` validan **las dos** monedas contra
  `viajes_monedas`, con mensajes diferenciados.
- La moneda de liquidación se resuelve en el parseo del comando, así el error aparece
  cuando se recibe el formulario y no al guardar.

### 3.3 Infraestructura

- `CodeIgniterTripLegRepository` persiste `moneda_liquidacion` y `cotizacion_liquidacion`
  junto a los importes originales y sus equivalentes en pesos.
- `CodeIgniterOperationalTripReadModel` devuelve ambas columnas. Cuando un tramo viene
  sin moneda de liquidación (tramos cargados antes del cambio), el read model devuelve la
  de facturación: la lectura nunca queda incompleta.

### 3.4 Migración — `2026-11-12-150000_AddTripLegLiquidationCurrency.php`

- Agrega `moneda_liquidacion` (`CHAR(3)`, null) y `cotizacion_liquidacion`
  (`DECIMAL(18,6)`, null) a `viaje_operativo_tramos`.
- `up()` es idempotente e invalida la caché de nombres de columna de Forge antes de cada
  comprobación, igual que la migración de fechas.
- **Backfill**: los tramos que ya existían reciben la moneda y la cotización de
  facturación, que es lo que valía antes. Ningún dato histórico cambia de valor.
- `down()` **se niega a correr** si hay algún tramo con la liquidación en una moneda
  distinta a la de facturación: borrar la columna perdería ese dato.

### 3.5 Presentación

- En el alta de viaje y en los formularios de tramo (agregar y editar) la moneda se pide
  **por flete**: *Moneda del flete de facturación* y *Moneda del flete de liquidación*, con
  su cotización cada una.
- Botón **En pesos** junto a la moneda de liquidación: la deja en ARS con cotización 1, y
  el campo de cotización queda deshabilitado con la explicación a la vista.
- Mientras la liquidación sigue a la facturación, la cotización se muestra **deshabilitada
  y en gris**: es la misma de la de facturación. Si el operador la cambia, aparece el
  campo para cargar la cotización de esa moneda.
- La tabla *Tramos* sigue mostrando los importes **en pesos** (como hasta ahora) y suma
  el importe original con su moneda en el tooltip: `USD 4.700,00`.
- La lógica de las dos monedas quedó en un módulo compartido
  (`frontend/src/composables/useTripLegMoney.js`), usado por las dos pantallas, para que
  el alta y la edición se comporten igual.

---

## 4. Verificación

### 4.1 Suites

- **PHPUnit:** 1787 pruebas, 0 fallos, 45 omitidas (`php vendor/bin/phpunit --no-coverage`).
- **Vitest:** 469 pruebas en 85 archivos, 0 fallos (`npx vitest run`).
- `php -l` sin errores en los 20 archivos PHP tocados.

Pruebas nuevas o actualizadas:

- `tests/unit/Domain/Trips/TripLegAmountTest.php` (nuevo): importe con moneda y
  cotización, pesos sin conversión, importe vacío, monto negativo, moneda inválida,
  cotización en cero.
- `tests/unit/Domain/Trips/TripLegTest.php`: cada flete con su moneda, comisión calculada
  sobre la liquidación en pesos.
- `tests/unit/Application/Trips/AddTripLegTest.php`: la liquidación hereda la moneda de
  facturación; se puede cargar en pesos con la facturación en dólares; se puede cargar en
  otra moneda extranjera con su cotización; se rechaza la moneda sin cotización; se
  rechaza una moneda que no está en el catálogo.
- `tests/unit/Application/Trips/UpdateTripLegTest.php`: edición de un tramo con la
  liquidación en pesos.
- `tests/unit/Infrastructure/Trips/CodeIgniterTripLegRepositoryTest.php`: persistencia de
  cada flete con su moneda y su cotización.
- `tests/unit/Infrastructure/Trips/CodeIgniterOperationalTripReadModelTest.php`: lectura de
  las dos columnas y respaldo a la moneda de facturación.
- `tests/database/AddTripLegLiquidationCurrencyMigrationTest.php` (nuevo): `up()` con
  backfill, `up()` idempotente, `down()` y `down()` bloqueado.
- `frontend/tests/operations/TripOperations{DetailPage,CreatePage}.test.js`: el tooltip del
  importe original, la liquidación en pesos en el modal y en el alta, y la liquidación que
  sigue a la facturación.

### 4.2 MariaDB real (fasa_189)

Sobre **MariaDB 11.8.9** real (contenedor `mantenimiento-test-db`, base descartable
`mantenimiento_test`, red `mantenimiento-test-net`), con el código montado en el runner
`/home/fasa/mantenimiento-test-runner`:

1. **Cadena de migraciones completa.** `php spark migrate --all` aplicó la auditoría, el
   diario de bordo, los fletes opcionales, las fechas de tramo y la migración nueva.
2. **Aplicación con datos previos.** Con dos tramos sembrados (uno en USD a 1250 y otro en
   ARS), la migración dejó `moneda_liquidacion = USD / 1250.000000` y
   `ARS / 1.000000` respectivamente: el backfill copia la moneda de facturación.
3. **`down()` protegido.** Con un tramo con liquidación en ARS y facturación en USD, el
   `rollback` se negó:
   `down() no puede borrar la moneda de liquidacion mientras haya tramos con liquidacion en
   una moneda distinta a la de facturacion.`
4. **Escritura y lectura reales.** Con el repositorio y el read model contra la base:
   - facturación `USD @ 1250.000000` → 4.700,00 USD → **5.875.000,00 ARS**
   - liquidación `ARS @ 1.000000` → 5.875.000,00 → **5.875.000,00 ARS**
   - lectura: `moneda=USD moneda_liquidacion=ARS cotizacion_liquidacion=1`

El caso del operador queda cubierto: **el flete de liquidación entra en pesos, sin
convertir a mano, y la comisión sale sobre ese importe.**

---

## 5. Publicación

- Rama: `feat/viajes-moneda-liquidation-tramo`.
- **PR #523** a `main`, CI en verde (2m19s), merge squash `08517673`.
- **Deploy quirúrgico por FTPS a Ferozo** (`scripts/deploy-ferozo.py --skip-tests --yes`),
  del commit `648d57ca` al `08517673`:

| Paso | Resultado |
|---|---|
| Archivos subidos | **41** (15 de `app/` + migración + 26 de `assets/dashboard/`) |
| Bundle anterior | conservado en Ferozo (`main-VFz3ppsr.js`); se limpia aparte con remote-prune |
| Migración por el portón HTTP | `MIGRATION_STATUS_HTTP=200` → `MIGRATIONS=OK` |
| `migrate.php` | subido, ejecutado y **borrado** (`HTTP 404`) |
| Smoke tests | `login` 200, `favicon.ico` 200, `.env` 403, `app/` 403, `vendor/` 403, `writable/` 403, `migrate.php` 404 |
| Hash local vs remoto | `HASH_LOCAL=OK` |
| Bundle nuevo en producción | `main-DWguh_tA.js` 200, `profile-CcENJYER.js` 200, manifest 200 |
| Ruta de viajes | `/viajes/operativos/1` → 302 al login (sin sesión) |

**Sin backup previo de `viaje_operativo_tramos`**: la migración es aditiva y nullable, no
toca ni reescribe columnas existentes y el backfill solo copia valores que ya estaban. El
`down()` está protegido para que la reversión no pueda perder el dato. Queda asentado por
transparencia, igual que en el despliegue de las fechas de tramo.

---

## 6. Pendientes y notas

1. **Aceptación visual pendiente del cliente.** No hay sesión autenticada del usuario en
   este entorno: no se pudo ver la pantalla en 1638x969 ni en 1366x768. La verificación de
   interfaz es de comportamiento (pruebas de componente sobre el DOM real), no visual.
2. **Cero conversión, cero redondeo en el medio.** El importe original se conserva tal
   como lo infos el operador y la conversión a pesos ocurre una sola vez, al guardar.
   Coherente con el resto del contexto.
3. **No se agregó el tramo al resumen en moneda original.** La tabla y los PDF muestran
   pesos, como hasta ahora; el original queda en el tooltip de la tabla.
4. **Cadena de migraciones rota desde antes (preexistente).** `2026-11-06-100000_AddPrincipalFlagToLocalities`
   corta la cadena con un error de sintaxis en el `UPDATE` de las localidades de Brasil
   (`provincia_id IN ()`). No afecta producción (base ya migrada) pero **bloquea
   instalaciones limpias**. Para la verificación en `fasa_189` se saltó esa migración
   insertando su fila en `migrations` con el FQN en `class` y `namespace = 'App'`. Es un
   pendiente del contexto de Geografía, ajeno a este cambio.
5. **Base de verificación `mantenimiento_verify` (fasa_189) quedó inconsistente** de una
   sesión anterior: tenía registrada la migración del diario de bordo pero le faltaba la
   columna `viaje_operativo_otros_haberes.diario_bordo_id`. Se usó la base
   `mantenimiento_test`, que se reconstruyó completa para esta verificación. Ambas son
   descartables.
