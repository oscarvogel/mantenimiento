/**
 * Corrección global: la rueda del mouse no cambia el valor de los campos numéricos.
 *
 * Comportamiento nativo del navegador: un `<input type="number">` con el foco
 * puesto consume la rueda del mouse y suma o resta un `step` al valor. En los
 * formularios de costos (cantidad, precio, kilómetros, horómetro) el número que
 * queda guardado deja de ser el que el operador escribió: un precio de 11.999
 * pasa a 12.000 con un solo notch de rueda y el chofer ve otra numeración.
 *
 * La corrección vive en un único lugar y aplica a todos los formularios de la
 * aplicación: se escucha `wheel` sobre el documento y, cuando el objetivo es un
 * campo numérico, se cancela el comportamiento nativo. Como el navegador tampoco
 * desplazaba la página en ese momento, el desplazamiento se reenvía al
 * contenedor scrolleable más cercano para que la pantalla siga respondiendo.
 *
 * Evidencia del comportamiento nativo (Chromium 5.15.x / Chrome de planta):
 *   - campo enfocado + rueda -> el valor cambia y la página NO se desplaza;
 *   - campo sin enfocar + rueda -> el valor no cambia y la página se desplaza.
 */

const LINE_HEIGHT_PX = 16
const SCROLLABLE_OVERFLOW = new Set(['auto', 'scroll', 'overlay'])

// Valores de `WheelEvent.deltaMode` (DOM Level 2): se usan literales para no
// depender de la disponibilidad de la clase `WheelEvent` en el entorno.
const DELTA_PIXEL = 0
const DELTA_LINE = 1
const DELTA_PAGE = 2

/**
 * ¿El elemento es un campo numérico al que la rueda del navegador le puede
 * cambiar el valor?
 */
export function isNumericField(target) {
  return target instanceof HTMLInputElement && target.type === 'number'
}

/**
 * Contenedor scrolleable más cercano al campo, capaz de desplazarse de verdad.
 * Devuelve `null` cuando corresponde desplazar la página entera.
 */
export function resolveScrollTarget(element) {
  let node = element?.parentElement ?? null

  while (node) {
    const overflowY = window.getComputedStyle(node).overflowY
    if (SCROLLABLE_OVERFLOW.has(overflowY) && node.scrollHeight > node.clientHeight) {
      return node
    }
    node = node.parentElement
  }

  return null
}

/**
 * Desplazamiento de la rueda en píxeles, normalizando los distintos `deltaMode`.
 */
export function wheelDeltaInPixels(event, scrollTarget) {
  if (event.deltaMode === DELTA_LINE) {
    return event.deltaY * LINE_HEIGHT_PX
  }

  if (event.deltaMode === DELTA_PAGE) {
    const pageHeight = scrollTarget?.clientHeight ?? window.innerHeight
    return event.deltaY * pageHeight
  }

  return event.deltaY
}

function applyScroll(scrollTarget, delta) {
  if (scrollTarget) {
    scrollTarget.scrollTop += delta
    return
  }

  window.scrollBy(0, delta)
}

let guardInstalled = false

/**
 * Instala el guard. Es idempotente: llamarlo más de una vez no duplica el
 * listener ni el trabajo que hace.
 */
export function installNumberInputWheelGuard() {
  if (guardInstalled) return
  guardInstalled = true

  document.addEventListener('wheel', (event) => {
    const target = event.target
    if (!isNumericField(target)) return

    // Cancela el cambio de valor nativo del navegador.
    event.preventDefault()

    // El navegador tampoco desplazaba la página mientras el campo tenía el
    // foco: se reenvía el desplazamiento para no perder ese gesto.
    const scrollTarget = resolveScrollTarget(target)
    applyScroll(scrollTarget, wheelDeltaInPixels(event, scrollTarget))
  }, { passive: false })
}
