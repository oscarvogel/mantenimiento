import { afterEach, beforeAll, describe, expect, it } from 'vitest'
import { installGlobalBehaviors, shakeFirstInvalid } from './globals.js'

const nextFrame = () => new Promise((resolve) => requestAnimationFrame(resolve))

describe('campo con error que se sacude', () => {
  beforeAll(() => {
    installGlobalBehaviors()
    // jsdom no navega.
    window.addEventListener('submit', (event) => event.preventDefault())
  })

  afterEach(() => { document.body.innerHTML = '' })

  it('al guardar con un campo obligatorio vacío, solo el primero recibe el foco y se sacude', async () => {
    document.body.innerHTML = '<form method="post"><input id="a" required><input id="b" required><button>Guardar</button></form>'
    // El navegador dispara `invalid` al enviar; jsdom solo con reportValidity().
    document.querySelector('form').reportValidity()
    await Promise.resolve()

    const [first, second] = document.querySelectorAll('input')
    expect(document.activeElement).toBe(first)
    expect(first.classList).toContain('ui-shake')
    expect(second.classList).not.toContain('ui-shake')

    first.dispatchEvent(new Event('animationend'))
    expect(first.classList).not.toContain('ui-shake')
  })

  it('con la validación propia de Vue, se sacude el primer campo que quedó marcado', async () => {
    document.body.innerHTML = '<form><input id="a"><input id="b"><button>Guardar</button></form>'
    const form = document.querySelector('form')
    form.addEventListener('submit', (event) => {
      event.preventDefault()
      form.querySelector('#b').setAttribute('aria-invalid', 'true')
    })
    form.requestSubmit()
    await nextFrame()

    expect(form.querySelector('#b').classList).toContain('ui-shake')
    expect(form.querySelector('#a').classList).not.toContain('ui-shake')
  })

  it('mientras se escribe no se sacude, aunque el campo quede marcado', async () => {
    document.body.innerHTML = '<form><input id="a"></form>'
    const input = document.querySelector('input')
    input.setAttribute('aria-invalid', 'true')
    input.dispatchEvent(new Event('input', { bubbles: true }))
    await nextFrame()

    expect(input.classList).not.toContain('ui-shake')
  })

  it('al cargar una página que volvió con errores, se sacude el primero', () => {
    document.body.innerHTML = '<main><input id="a"><input id="b" aria-invalid="true"><input id="c" aria-invalid="true"></main>'
    expect(shakeFirstInvalid(document.querySelector('main'))?.id).toBe('b')
    expect(document.querySelectorAll('.ui-shake')).toHaveLength(1)
  })
})
