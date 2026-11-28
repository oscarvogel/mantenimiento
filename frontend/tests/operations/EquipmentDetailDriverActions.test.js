import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'

const source = readFileSync('src/pages/operations/EquipmentDetailPage.vue', 'utf8')

describe('acciones de chofer en la ficha del equipo', () => {
  it('comparte fila, separación de 12 px y dimensiones iguales', () => {
    expect(source).toContain('class="grid max-w-md grid-cols-1 gap-3 sm:grid-cols-2"')
    expect(source).toContain('dangerButtonLarge')
    expect(source).toContain('min-h-11')
    expect(source).toContain('px-4 py-2.5')
    expect(source).toContain('rounded-lg')
    expect(source).toContain('text-sm font-semibold')
    expect(source).toContain('border border-danger/30')
  })

  it('mantiene el primario y el danger outline existentes', () => {
    expect(source).toContain('${primaryButton} w-full')
    expect(source).toContain('${dangerButtonLarge} w-full')
  })

  it('el disparador de desvinculación no envía el formulario de asignación', () => {
    expect(source).toContain('type="button"')
    expect(source).toContain('@click="toggleUnassign"')
    expect(source).toContain('aria-controls="equipment-driver-unassign"')
  })

  it('conserva el formulario y la confirmación de desvinculación', () => {
    expect(source).toContain(':action="data.driverAssignment.unassignUrl"')
    expect(source).toContain('data-confirm')
    expect(source).toContain('<summary class="sr-only"')
  })
})
