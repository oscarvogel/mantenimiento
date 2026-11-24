import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
  installNumberInputWheelGuard,
  isNumericField,
  resolveScrollTarget,
  wheelDeltaInPixels,
} from '../../src/ui/numberInputWheel.js'

function wheelEvent({ deltaY = 120, deltaMode = 0 } = {}) {
  const event = new Event('wheel', { bubbles: true, cancelable: true })
  Object.defineProperty(event, 'deltaY', { value: deltaY })
  Object.defineProperty(event, 'deltaMode', { value: deltaMode })
  return event
}

function scrollableBox({ scrollHeight = 800, clientHeight = 200 } = {}) {
  const box = document.createElement('div')
  box.style.overflowY = 'auto'
  Object.defineProperty(box, 'scrollHeight', { value: scrollHeight })
  Object.defineProperty(box, 'clientHeight', { value: clientHeight })
  return box
}

function numberInput({ value = '12.00', step = '0.01' } = {}) {
  const input = document.createElement('input')
  input.type = 'number'
  input.step = step
  input.value = value
  return input
}

describe('numberInputWheel: la rueda no toca los campos numéricos', () => {
  beforeEach(() => {
    // jsdom no implementa el desplazamiento de la ventana: se intercepta para
    // que el ruido de "Not implemented" no ensucie la salida de la suite.
    vi.spyOn(window, 'scrollBy').mockImplementation(() => {})
    installNumberInputWheelGuard()
  })

  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('cancela el comportamiento nativo del navegador sobre un campo numérico', () => {
    const input = numberInput()
    document.body.appendChild(input)
    input.focus()

    const event = wheelEvent()
    input.dispatchEvent(event)

    expect(event.defaultPrevented).toBe(true)
    expect(input.value).toBe('12.00')
  })

  it('deja intactos los campos que no son numéricos', () => {
    const text = document.createElement('input')
    text.type = 'text'
    document.body.appendChild(text)

    const event = wheelEvent()
    text.dispatchEvent(event)

    expect(event.defaultPrevented).toBe(false)
  })

  it('no toca los select ni los checkbox', () => {
    const select = document.createElement('select')
    const checkbox = document.createElement('input')
    checkbox.type = 'checkbox'
    document.body.append(select, checkbox)

    const selectEvent = wheelEvent()
    select.dispatchEvent(selectEvent)
    const checkboxEvent = wheelEvent()
    checkbox.dispatchEvent(checkboxEvent)

    expect(selectEvent.defaultPrevented).toBe(false)
    expect(checkboxEvent.defaultPrevented).toBe(false)
  })

  it('reenvía el desplazamiento al contenedor scrolleable más cercano', () => {
    const box = scrollableBox()
    const input = numberInput()
    box.appendChild(input)
    document.body.appendChild(box)
    input.focus()

    const event = wheelEvent({ deltaY: 120 })
    input.dispatchEvent(event)

    expect(event.defaultPrevented).toBe(true)
    expect(box.scrollTop).toBe(120)
  })

  it('desplaza la página cuando el campo no está dentro de un contenedor scrolleable', () => {
    const scrollBy = vi.spyOn(window, 'scrollBy').mockImplementation(() => {})
    const input = numberInput()
    document.body.appendChild(input)
    input.focus()

    const event = wheelEvent({ deltaY: -80 })
    input.dispatchEvent(event)

    expect(event.defaultPrevented).toBe(true)
    expect(scrollBy).toHaveBeenCalledWith(0, -80)
  })

  it('normaliza el desplazamiento cuando la rueda viene en líneas o páginas', () => {
    expect(wheelDeltaInPixels(wheelEvent({ deltaY: 3, deltaMode: 1 }), null)).toBe(48)
    expect(wheelDeltaInPixels(wheelEvent({ deltaY: 2, deltaMode: 2 }), null)).toBe(2 * window.innerHeight)
  })

  it('no instala el listener dos veces', () => {
    const input = numberInput()
    document.body.appendChild(input)
    input.focus()

    installNumberInputWheelGuard()

    const event = wheelEvent()
    input.dispatchEvent(event)

    expect(event.defaultPrevented).toBe(true)
    expect(input.value).toBe('12.00')
  })
})

describe('numberInputWheel: helpers', () => {
  it('reconoce solamente los input de tipo number', () => {
    const numeric = numberInput()
    const text = document.createElement('input')
    text.type = 'text'

    expect(isNumericField(numeric)).toBe(true)
    expect(isNumericField(text)).toBe(false)
    expect(isNumericField(null)).toBe(false)
    expect(isNumericField(document.createElement('div'))).toBe(false)
  })

  it('ignora los contenedores que no pueden desplazarse', () => {
    const box = scrollableBox({ scrollHeight: 200, clientHeight: 200 })
    const input = numberInput()
    box.appendChild(input)
    document.body.appendChild(box)

    expect(resolveScrollTarget(input)).toBeNull()
  })
})
