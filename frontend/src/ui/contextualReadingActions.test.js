import { afterEach, describe, expect, it } from 'vitest'
import { installContextualReadingActions } from './contextualReadingActions.js'

afterEach(() => { document.body.innerHTML = '' })

const navigation = [{ key: 'quick-readings', href: '/mantenimiento/lecturas/rapidas' }]

describe('installContextualReadingActions', () => {
  it('agrega Registrar km/horas a cada equipo activo del listado', () => {
    const root = document.createElement('div')
    root.innerHTML = '<div><a href="/mantenimiento/equipos/10">Ficha</a></div><div><a href="/mantenimiento/equipos/20">Ficha</a></div>'
    document.body.appendChild(root)
    installContextualReadingActions(root, {
      page: 'assets-index', navigation,
      data: { equipment: { items: [
        { id: 10, code: 'CAM-25', status: 'ACTIVO', detailUrl: '/mantenimiento/equipos/10' },
        { id: 20, code: 'TR-04', status: 'BAJA', detailUrl: '/mantenimiento/equipos/20' },
      ] } },
    })
    const actions = root.querySelectorAll('[data-contextual-reading-action="true"]')
    expect(actions).toHaveLength(1)
    expect(actions[0].textContent).toContain('Registrar km/horas')
    expect(actions[0].href).toContain('q=CAM-25')
  })

  it('agrega la acción en la ficha y preselecciona por código vía query', () => {
    const root = document.createElement('div')
    root.innerHTML = '<header><h1>Ficha del equipo</h1></header>'
    document.body.appendChild(root)
    installContextualReadingActions(root, {
      page: 'equipment-detail', navigation,
      data: { equipment: { id: 10, code: 'CAM-25', status: 'ACTIVO' } },
    })
    const action = root.querySelector('[data-contextual-reading-action="true"]')
    expect(action).not.toBeNull()
    expect(action.href).toContain('q=CAM-25')
  })

  it('no muestra acciones si el shell no expone el permiso de lecturas', () => {
    const root = document.createElement('div')
    root.innerHTML = '<header><h1>Ficha</h1></header>'
    installContextualReadingActions(root, { page: 'equipment-detail', navigation: [], data: { equipment: { id: 10, code: 'CAM-25', status: 'ACTIVO' } } })
    expect(root.querySelector('[data-contextual-reading-action="true"]')).toBeNull()
  })
})
