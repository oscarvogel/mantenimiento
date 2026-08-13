import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import PreventivePlansPage from '../../src/pages/operations/PreventivePlansPage.vue'
import { preventivePlansData } from './fixtures.js'

const data = () => ({
  ...preventivePlansData,
  wizardEquipmentId: 9,
  routes: { ...preventivePlansData.routes, createFromTemplate: '/mantenimiento/planes/desde-plantilla' },
  catalogs: {
    ...preventivePlansData.catalogs,
    equipment: [{
      ...preventivePlansData.catalogs.equipment[0],
      brandName: 'IVECO', modelName: 'TECTOR', currentKm: 185000, assignedServiceTypeIds: [],
    }],
    templateDefaults: [
      { ...preventivePlansData.catalogs.templateDefaults[0], id: 14, templateId: 2, brand: null, model: null, intervalKm: 10000, intervalDays: null },
      { ...preventivePlansData.catalogs.templateDefaults[0], id: 15, templateId: 3, brand: 'IVECO', model: 'TECTOR', intervalKm: 20000, intervalDays: null },
    ],
  },
})

describe('asistente de planes preventivos', () => {
  it('elige la plantilla más específica, permite desmarcar y previsualiza sin mezclar la lectura actual', async () => {
    const wrapper = mount(PreventivePlansPage, { props: { data: data() } })
    const form = wrapper.get('form[action="/mantenimiento/planes/desde-plantilla"]')

    expect(form.find('input[name="planes[14][seleccionado]"]').exists()).toBe(false)
    const selected = form.get('input[name="planes[15][seleccionado]"]')
    expect(selected.element.checked).toBe(true)
    await form.get('input[name="planes[15][base_km]"]').setValue('170000')
    expect(form.text()).toContain('Próximo: 190000 km')
    expect(form.text()).toContain('AL DIA')

    await selected.setValue(false)
    expect(form.get('button[type="submit"]').attributes()).toHaveProperty('disabled')
  })
})
