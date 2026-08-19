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

  it('instala el cierre cuando Vue agrega el formulario después del montaje', async () => {
    document.body.innerHTML = '<div id="root"></div>'

    const root = document.getElementById('root')
    const stop = installWorkOrderTaskClosure(root, {
      data: {
        orders: [{
          id: 9,
          status: 'EN_PROCESO',
          tasks: [{ id: 51, description: 'Filtro aceite', status: 'PENDIENTE' }],
        }],
      },
    })

    root.innerHTML = `
      <form id="close-order-9">
        <label for="order-work-9">Trabajo realizado</label>
        <textarea id="order-work-9" name="trabajo_realizado" required></textarea>
      </form>
    `

    await new Promise((resolve) => setTimeout(resolve, 0))

    expect(root.querySelector('#order-work-9').hidden).toBe(true)
    expect(root.querySelector('[data-testid="task-closure-9"]')).not.toBeNull()
    stop?.()
  })
})
