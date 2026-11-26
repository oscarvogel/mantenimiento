import { describe, expect, it } from 'vitest'
import { groupMyWork, isMyWorkView, withFieldShortcuts } from './fieldShortcuts.js'

const nav = (...keys) => keys.map((key) => ({ key, label: key, href: `/mantenimiento/${key}` }))

describe('withFieldShortcuts', () => {
  it('agrega Mi trabajo filtrado por el usuario e Informar falla', () => {
    const result = withFieldShortcuts(nav('work-orders', 'work-requests', 'quick-readings'), { id: 7 })
    const myWork = result.find((item) => item.key === 'my-work')
    const failure = result.find((item) => item.key === 'report-failure')
    expect(myWork.href).toBe('/mantenimiento/work-orders?responsable_id=7&vista=mi-trabajo')
    expect(failure.href).toBe('/mantenimiento/quick-readings?accion=falla')
  })

  it('no inventa accesos que el servidor no habilitó', () => {
    // A requester without equipment access: no field page to report from.
    const keys = withFieldShortcuts(nav('work-requests'), { id: 7 }).map((item) => item.key)
    expect(keys).toEqual(['work-requests'])
    // Without the user id there is no way to filter "my" orders.
    expect(withFieldShortcuts(nav('work-orders'), {}).map((item) => item.key)).toEqual(['work-orders'])
  })

  it('marca activo Mi trabajo, no Órdenes, cuando se está en esa vista', () => {
    const navigation = nav('work-orders').map((item) => ({ ...item, active: true }))
    const result = withFieldShortcuts(navigation, { id: 7 }, '?responsable_id=7&vista=mi-trabajo')
    expect(result.find((item) => item.key === 'my-work').active).toBe(true)
    expect(result.find((item) => item.key === 'work-orders').active).toBe(false)
    expect(isMyWorkView('?vista=mi-trabajo')).toBe(true)
  })
})

describe('groupMyWork', () => {
  it('ordena el trabajo como se hace: en curso, para empezar, en espera, terminadas', () => {
    const orders = [
      { id: 1, status: 'FINALIZADA' },
      { id: 2, status: 'ESPERA_REPUESTOS' },
      { id: 3, status: 'EMITIDA' },
      { id: 4, status: 'EN_PROCESO' },
    ]
    expect(groupMyWork(orders).map((section) => [section.title, section.orders.map((order) => order.id)])).toEqual([
      ['En curso', [4]],
      ['Para empezar', [3]],
      ['En espera de repuestos', [2]],
      ['Terminadas', [1]],
    ])
  })
})
