import { describe, expect, it } from 'vitest'
import { groupNavigation } from './navigationGroups.js'

const item = (key) => ({ key, label: key, href: `/${key}` })

describe('groupNavigation', () => {
  it('pone lo diario primero y respeta el orden de cada sección', () => {
    const groups = groupNavigation(['users', 'work-orders', 'equipment', 'dashboard', 'quick-readings'].map(item))
    expect(groups.map((group) => group.title)).toEqual(['Operación', 'Flota', 'Configuración'])
    expect(groups[0].items.map((entry) => entry.key)).toEqual(['dashboard', 'quick-readings', 'work-orders'])
  })

  it('solo muestra las secciones con ítems permitidos', () => {
    const groups = groupNavigation([item('quick-readings')])
    expect(groups).toHaveLength(1)
    expect(groups[0].items[0].key).toBe('quick-readings')
  })

  it('no pierde un ítem nuevo que todavía no tiene sección', () => {
    const groups = groupNavigation([item('dashboard'), item('modulo-nuevo')])
    expect(groups.at(-1).title).toBe('Más')
    expect(groups.at(-1).items.map((entry) => entry.key)).toEqual(['modulo-nuevo'])
  })

  it('anida el consumo por viaje dentro de telemetría', () => {
    const groups = groupNavigation([item('fleet-telemetry'), item('fleet-consumption')])
    expect(groups.flatMap((group) => group.items).map((entry) => entry.key)).toEqual(['fleet-telemetry'])
  })
})
