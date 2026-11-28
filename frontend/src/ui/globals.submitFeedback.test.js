import { afterEach, beforeAll, describe, expect, it } from 'vitest'
import { installGlobalBehaviors } from './globals.js'

// jsdom no navega: se corta el envío al final, después de que corrió el aviso.
const stopNavigation = (event) => event.preventDefault()

describe('aviso de envío en formularios', () => {
  beforeAll(() => {
    installGlobalBehaviors()
    window.addEventListener('submit', stopNavigation)
  })

  afterEach(() => { document.body.innerHTML = '' })

  const submit = (html) => {
    document.body.innerHTML = html
    const form = document.querySelector('form')
    const button = form.querySelector('button')
    form.requestSubmit(button)
    return { form, button }
  }

  it('un POST muestra el aro, conserva la etiqueta y no deja enviar dos veces', () => {
    const { form, button } = submit('<form method="post" action="/guardar"><button type="submit"><svg></svg>Guardar</button></form>')

    expect(button.classList).toContain('ui-submitting')
    expect(button.disabled).toBe(true)
    expect(button.textContent).toBe('Guardar')
    expect(button.querySelector('svg')).not.toBeNull()
    expect(form.getAttribute('aria-busy')).toBe('true')
  })

  it('con data-loading-label cambia el texto (login)', () => {
    const { button } = submit('<form method="post" data-submit-feedback data-loading-label="Ingresando…"><button>Ingresar</button></form>')
    expect(button.textContent).toBe('Ingresando…')
  })

  it('no toca filtros GET, formularios de Vue ni los que abren otra pestaña', () => {
    for (const html of [
      '<form method="get"><button>Filtrar</button></form>',
      '<form method="post" target="_blank"><button>Imprimir</button></form>',
    ]) {
      expect(submit(html).button.classList).not.toContain('ui-submitting')
    }

    document.body.innerHTML = '<form method="post"><button>Guardar</button></form>'
    const form = document.querySelector('form')
    form.addEventListener('submit', (event) => event.preventDefault())
    form.requestSubmit()
    expect(form.querySelector('button').disabled).toBe(false)
  })

  it('al volver con Atrás desde la caché el botón queda usable', () => {
    const { button } = submit('<form method="post" data-loading-label="Guardando…"><button>Guardar</button></form>')

    window.dispatchEvent(Object.assign(new Event('pageshow'), { persisted: true }))

    expect(button.disabled).toBe(false)
    expect(button.classList).not.toContain('ui-submitting')
    expect(button.textContent).toBe('Guardar')
  })
})
