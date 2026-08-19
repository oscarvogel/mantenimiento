import { afterEach, describe, expect, it } from 'vitest'
import { installWorkOrderTaskClosure } from '../../src/ui/workOrderTaskClosure.js'

afterEach(() => {
  document.body.innerHTML = ''
})

describe('workOrderTaskClosure', () => {
  it('reemplaza el texto global por resultado individual de tareas', () => {
    document.body.innerHTML = `
      <div id="root">
        <form id="close-order-7">
          <label for="order-work-7">Trabajo realizado</label>
          <textarea id="order-work-7" name="trabajo_realizado" required></textarea>
        </form>
      </div>
    `

    const root = document.getElementById('root')
    installWorkOrderTaskClosure(root, {
      data: {
        orders: [{
          id: 7,
          status: 'EN_PROCESO',
          tasks: [
            { id: 41, description: 'Filtro aceite', status: 'PENDIENTE' },
            { id: 42, description: 'Aceite motor', status: 'PENDIENTE' },
          ],
        }],
      },
    })

    const legacy = root.querySelector('#order-work-7')
    expect(legacy.hidden).toBe(true)
    expect(legacy.hasAttribute('name')).toBe(false)
    expect(root.querySelector('[name="trabajo_realizado[41][resultado]"]')).not.toBeNull()
    expect(root.querySelector('[name="trabajo_realizado[41][detalle]"]')).not.toBeNull()
    expect(root.querySelector('[name="trabajo_realizado[42][resultado]"]')).not.toBeNull()
    expect(root.textContent).toContain('Pendiente / no realizada')
    expect(root.textContent).toContain('No aplica')
  })

  it('no altera órdenes que no están en proceso', () => {
    document.body.innerHTML = `
      <div id="root">
        <form id="close-order-8">
          <textarea id="order-work-8" name="trabajo_realizado" required></textarea>
        </form>
      </div>
    `

    const root = document.getElementById('root')
    installWorkOrderTaskClosure(root, {
      data: { orders: [{ id: 8, status: 'FINALIZADA', tasks: [{ id: 50, description: 'Filtro' }] }] },
    })

    expect(root.querySelector('#order-work-8').hidden).toBe(false)
    expect(root.querySelector('[name="trabajo_realizado[50][resultado]"]')).toBeNull()
  })
})
