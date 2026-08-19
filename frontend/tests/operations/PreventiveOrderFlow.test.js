import { afterEach, describe, expect, it } from 'vitest'
import { installPreventiveOrderFlow } from '../../src/ui/preventiveOrderFlow.js'

afterEach(() => {
  document.body.innerHTML = ''
})

describe('preventive order flow', () => {
  it('abre la generación en otra pestaña y bloquea doble submit visual', () => {
    document.body.innerHTML = `
      <div id="root">
        <form action="/mantenimiento/planes/2/orden">
          <button data-testid="generate-order-2">Generar OT</button>
        </form>
      </div>`

    const root = document.getElementById('root')
    installPreventiveOrderFlow(root, { data: { plans: { items: [{ id: 2, openOrder: null }] } } })

    const form = root.querySelector('form')
    const button = root.querySelector('[data-testid="generate-order-2"]')
    expect(form.target).toBe('_blank')

    form.dispatchEvent(new Event('submit'))
    expect(button.disabled).toBe(true)
    expect(button.textContent).toBe('Abriendo OT…')
  })

  it('reemplaza Generar OT por acceso a la OT abierta', () => {
    document.body.innerHTML = `
      <div id="root">
        <form action="/mantenimiento/planes/2/orden">
          <button class="btn-primary" data-testid="generate-order-2">Generar OT</button>
        </form>
      </div>`

    const root = document.getElementById('root')
    installPreventiveOrderFlow(root, {
      data: {
        plans: {
          items: [{
            id: 2,
            openOrder: {
              id: 41,
              number: 'OT-2026-000041',
              printUrl: '/mantenimiento/ordenes/41/imprimir',
            },
          }],
        },
      },
    })

    expect(root.querySelector('form')).toBeNull()
    const link = root.querySelector('[data-testid="open-order-2"]')
    expect(link).not.toBeNull()
    expect(link.target).toBe('_blank')
    expect(link.getAttribute('rel')).toBe('noopener noreferrer')
    expect(link.textContent).toContain('OT-2026-000041')
  })
})
