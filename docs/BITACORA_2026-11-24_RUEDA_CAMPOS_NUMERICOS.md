# La rueda del mouse no cambia más los campos numéricos — 24/11/2026

**Pedido de Oscar (24/11/2026), en el grupo con Carlos Secre (TSA):**
*"guarda, que si vos haces con la ruedita el mouse y estás parado en un número te va
bajando centavos… pero si vos hacés con la ruedita el mouse y estás parado en un número
te va bajando centavos. si, pero voy a ver cómo arreglo eso, para que no tenga esa
opción de que con la ruedita suba o baje centavos, xq te confunde"*.

Contexto que llegó por audio:

- *"el de 11.999 es 12.000 y yo pongo 12.000 y automáticamente me aparece así. Y dice
  cantidad uno y está en peso argentino y todo"*.
- *"si bien no es la gran cosa porque son tres centavos, pero por ahí el tema de los
  choferes, si ven así como otra numeración, van a comenzar a decir cosas"*.

Objetivo declarado por Oscar: **que ningún campo con spinner cambie de valor al girar la
rueda del mouse**, en todos los formularios de la aplicación.

## 1. Qué estaba mal

No era un error del backend ni del redondeo: era el **comportamiento nativo del
navegador**. Un `<input type="number">` con el foco puesto consume la rueda del mouse y
suma o resta un `step` al valor.

Medido en Chromium real (Playwright, evento de rueda confiable, no sintético):

| Escenario | Valor | Desplazamiento de la página |
|---|---|---|
| Campo enfocado + rueda (nativo) | `11.999` → **`11.989`** | **no se desplaza** (queda 0) |
| Campo enfocado + rueda (con el guard de este PR) | `11.999` → **`11.999`** | sí se desplaza (120 px) |
| Campo sin enfocar + rueda | no cambia | se desplaza |

Consecuencias en la operación:

- El número guardado deja de ser el que el operador escribió. Un precio de **11.999**
  se converts en **12.000** con un solo notch de rueda (Chromium ajusta al `step` del
  campo), y ese importe es el que ve el chofer.
- El operador tampoco podía desplazar la pantalla: con el campo enfocado, la rueda se
  consumía en el input.
- El problema no era de una pantalla: el frontend tiene **132 campos `type="number"` en
  24 archivos** (Viajes, Combustible, Planes, Preventivo, Equipos, Gerencia, fuel,
  órdenes de trabajo, liquidaciones).

Aclaración sobre el otro extremo del audio: **no hay redondeo oculto al escribir**.
Chromium conserva el valor tipeado (`11.999` con `step="0.01"` queda `11.999` y marca
`stepMismatch`, sin redondear al salir del campo). El `11.999 → 12.000` lo producía la
rueda, no la escritura.

## 2. Qué se implementó

Un guard global, en un solo lugar, que cubre todos los formularios:

- `frontend/src/ui/numberInputWheel.js` (nuevo)
  - `installNumberInputWheelGuard()`: escucha `wheel` sobre el `document` con
    `{ passive: false }`; si el objetivo es un `input[type=number]`, cancela el
    comportamiento nativo (`preventDefault`) y **reenvía el desplazamiento** al
    contenedor scrolleable más cercano (o a la ventana) para que la pantalla siga
    respondiendo como antes.
  - `isNumericField()`, `resolveScrollTarget()` y `wheelDeltaInPixels()` quedan
    exportados para poder probarlos por separado.
  - La instalación es **idempotente**: llamarla más de una vez no duplica el listener.
- `frontend/src/ui/globals.js`: el guard se instala dentro de `installGlobalBehaviors()`,
  que ya se ejecutaba al arrancar el bundle. **No se tocó ningún componente de página.**
- `frontend/tests/ui/numberInputWheel.test.js` (nuevo): 9 tests con Vitest.
- `frontend/scripts/number-wheel-qa.mjs` (nuevo) + `npm run qa:wheel`: verificación en
  **Chromium real** con eventos de rueda confiables, que es la única forma de comprobar
  el comportamiento nativo (jsdom no lo reproduce).
- `assets/dashboard/**`: bundle recompilado, porque el portal sirve el build versionado.

## 3. Validaciones ejecutadas

| Comando | Resultado |
|---|---|
| `npm run qa:wheel` | **OK** — sin guard el valor pasa a `11.989`; con guard queda `11.999` y la página se desplaza 120 px |
| `npx vitest run` (suite completa) | **566 tests / 93 archivos en verde** |
| `npm run build` | OK (QA de encoding incluida: `ENCODING_QA=OK`) |
| `npm run verify:build-sync` | OK una vez commiteados los bundles |

## 4. Fuera de alcance (pendiente real)

- **Las cinco vistas Bootstrap renderizadas por PHP** (`maintenance_circuit/index.php`,
  `maintenance_circuit/equipment.php`, `public_equipment/reading.php`,
  `superadmin/index.php`, `assets/index.php`) tienen **15 campos numéricos** y **no
  cargan el bundle de Vue**: son HTML standalone con Bootstrap por CDN, así que el guard
  no los alcanza. Son pantallas del circuito preventivo y de administración global, fuera
  del flujo reportado; quedan señaladas para integrarlas (mismo script, un `<script>` por
  vista) en un PR aparte.
  *Actualización 2026-11-27:* `maintenance_circuit/*`, `superadmin/index.php` y `assets/index.php`
  se borraron: ningún controlador las renderizaba (las pantallas reales son de Vue). Solo queda
  `public_equipment/reading.php`.
- **No se tocó** la semántica de `step` ni la validación de los campos: un `stepMismatch`
  sigue marcando el campo como inválido, igual que antes.

## 5. Cómo se comprueba a mano

En cualquier pantalla con un campo numérico (por ejemplo, el detalle de un viaje):

1. Clic en un campo de precio o cantidad.
2. Girar la rueda del mouse sobre el campo.
3. El **valor no cambia** y la pantalla **se desplaza** normalmente.
