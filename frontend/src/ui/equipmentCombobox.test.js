import { afterEach, describe, expect, it, vi } from 'vitest'
import { enhanceEquipmentSelect, MAX_VISIBLE_RESULTS } from './equipmentCombobox.js'

afterEach(() => {
  document.body.innerHTML = ''
})

const buildSelect = (count = 3) => {
  const select = document.createElement('select')
  select.id = 'template-equipment'
  select.name = 'equipo_id'
  select.required = true
  select.innerHTML = '<option value="" disabled>Seleccionar equipo</option>'
  for (let index = 1; index <= count; index += 1) {
    const option = document.createElement('option')
    option.value = String(index)
    option.textContent = `EQ-${index}`
    select.append(option)
  }
  document.body.append(select)
  return select
}

const catalog = [
  { id: 1, code: 'AB499OK', plate: 'AA123BB', typeName: 'Camión', branchCode: 'TSAARG', branchName: 'TSA Argentina', brandName: 'Volvo', modelName: 'FM' },
  { id: 2, code: 'AC532DD', plate: 'AC532DD', typeName: 'Camión', branchCode: 'TSAARG', branchName: 'TSA Argentina', brandName: 'Scania', modelName: 'R450' },
  { id: 3, code: 'MOT-01', plate: null, typeName: 'Motoniveladora', branchCode: 'TSABR', branchName: 'TSA Brasil', brandName: 'John Deere', modelName: '620G' },
]

describe('equipmentCombobox', () => {
  it('busca por patente, tipo, sucursal, marca y modelo', () => {
    const select = buildSelect()
    const control = enhanceEquipmentSelect(select, catalog)

    control.input.value = 'aa123bb'
    control.input.dispatchEvent(new Event('input', { bubbles: true }))
    expect(control.list.textContent).toContain('AB499OK')
    expect(control.list.textContent).not.toContain('AC532DD')

    control.input.value = 'motoniveladora'
    control.input.dispatchEvent(new Event('input', { bubbles: true }))
    expect(control.list.textContent).toContain('MOT-01')

    control.input.value = 'john deere 620g'
    control.input.dispatchEvent(new Event('input', { bubbles: true }))
    expect(control.list.textContent).toContain('MOT-01')
  })

  it('actualiza el select original y conserva el equipo_id enviado por el formulario', () => {
    const select = buildSelect()
    const onChange = vi.fn()
    select.addEventListener('change', onChange)
    const control = enhanceEquipmentSelect(select, catalog)

    control.input.value = 'scania'
    control.input.dispatchEvent(new Event('input', { bubbles: true }))
    control.list.querySelector('[data-equipment-id="2"]').click()

    expect(select.value).toBe('2')
    expect(select.name).toBe('equipo_id')
    expect(control.input.value).toBe('AC532DD')
    expect(onChange).toHaveBeenCalledTimes(1)
  })

  it('limita la cantidad de resultados visibles para listas grandes', () => {
    const count = MAX_VISIBLE_RESULTS + 25
    const select = buildSelect(count)
    const largeCatalog = Array.from({ length: count }, (_, index) => ({
      id: index + 1,
      code: `EQ-${index + 1}`,
      typeName: 'Camión',
      branchCode: 'TSAARG',
    }))
    const control = enhanceEquipmentSelect(select, largeCatalog)

    control.input.dispatchEvent(new Event('focus'))

    expect(control.list.querySelectorAll('[role="option"]')).toHaveLength(MAX_VISIBLE_RESULTS)
  })
})
