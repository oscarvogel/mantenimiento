import { afterEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import PreventivePlansPage from '../../src/pages/operations/PreventivePlansPage.vue'
import { preventivePlansData } from './fixtures.js'

const wrappers = []

const makeData = (equipment = {}) => {
  const data = JSON.parse(JSON.stringify(preventivePlansData))
  data.catalogs.equipment = [{
    ...data.catalogs.equipment[0],
    brandName: 'Scania',
    modelName: 'R450',
    ...equipment,
  }]
  data.catalogs.serviceTypes = [
    { id: 3, code: 'SM', name: 'Service motor' },
    { id: 4, code: 'FR', name: 'Inspección frenos' },
  ]
  data.catalogs.templateDefaults = [
    { ...data.catalogs.templateDefaults[0], serviceTypeId: 3, serviceName: 'Service motor', intervalKm: 500, warningKm: 100, intervalDays: 180, warningDays: 30, templateName: 'Plantilla motor' },
    { ...data.catalogs.templateDefaults[0], id: 16, serviceTypeId: 4, serviceName: 'Inspección frenos', intervalKm: 1000, warningKm: 200, intervalDays: 90, warningDays: 15, templateName: 'Plantilla frenos' },
  ]
  return data
}

const openManual = async (data) => {
  const wrapper = mount(PreventivePlansPage, { props: { data } })
  wrappers.push(wrapper)
  await wrapper.get('[data-testid="open-manual-plan"]').trigger('click')
  return wrapper
}

afterEach(() => wrappers.splice(0).forEach((wrapper) => wrapper.unmount()))

describe('V3.1.3 · planes preventivos operativos', () => {
  it('oculta criterios específicos antes de elegir un equipo', async () => {
    const wrapper = await openManual(makeData())
    const form = wrapper.get('form[action="/mantenimiento/planes"][method="post"]')

    expect(form.find('input[name="intervalo_km"]').exists()).toBe(false)
    expect(form.find('input[name="intervalo_horas"]').exists()).toBe(false)
    expect(form.find('input[name="intervalo_dias"]').exists()).toBe(false)
  })

  it.each([
    ['solo kilómetros', { controlsKm: true, controlsHours: false }, true, false],
    ['solo horas', { controlsKm: false, controlsHours: true }, false, true],
    ['kilómetros y horas', { controlsKm: true, controlsHours: true }, true, true],
  ])('%s muestra únicamente los criterios soportados', async (_label, capabilities, hasKm, hasHours) => {
    const wrapper = await openManual(makeData(capabilities))
    const form = wrapper.get('form[action="/mantenimiento/planes"][method="post"]')
    await form.get('select[name="equipo_id"]').setValue('9')

    expect(form.find('input[name="intervalo_km"]').exists()).toBe(hasKm)
    expect(form.find('input[name="intervalo_horas"]').exists()).toBe(hasHours)
    expect(form.find('input[name="intervalo_dias"]').exists()).toBe(true)
  })

  it('cambiar el servicio cambia la referencia y los valores sugeridos', async () => {
    const wrapper = await openManual(makeData())
    const form = wrapper.get('form[action="/mantenimiento/planes"][method="post"]')
    await form.get('select[name="equipo_id"]').setValue('9')
    await form.get('select[name="tipo_servicio_id"]').setValue('3')
    expect(wrapper.text()).toContain('Referencia compatible: Plantilla motor')
    expect(form.get('input[name="intervalo_km"]').element.value).toBe('500')

    await form.get('select[name="tipo_servicio_id"]').setValue('4')
    expect(wrapper.text()).toContain('Referencia compatible: Plantilla frenos')
    expect(form.get('input[name="intervalo_km"]').element.value).toBe('1000')
  })

  it('calcula preview de kilómetros y horas en tiempo real', async () => {
    const wrapper = await openManual(makeData({ controlsKm: true, controlsHours: true, currentKm: 8000, currentHours: 1250 }))
    const form = wrapper.get('form[action="/mantenimiento/planes"][method="post"]')
    await form.get('select[name="equipo_id"]').setValue('9')
    await form.get('select[name="tipo_servicio_id"]').setValue('3')
    await form.get('input[name="base_km"]').setValue('8000')
    await form.get('input[name="intervalo_km"]').setValue('500')
    await form.get('input[name="anticipacion_km"]').setValue('100')
    await form.get('input[name="base_horas"]').setValue('1250.5')
    await form.get('input[name="intervalo_horas"]').setValue('50.5')
    await form.get('input[name="anticipacion_horas"]').setValue('8')

    const preview = wrapper.get('[data-testid="manual-preview"]')
    expect(preview.text()).toContain('Próximo mantenimiento: 8.500 km')
    expect(preview.text()).toContain('Avisar desde: 8.400 km')
    expect(preview.text()).toContain('1.301 h')
    expect(preview.text()).toContain('Avisar desde: 1.293 h')
  })

  it('calcula preview de fecha con formato local es-AR', async () => {
    const wrapper = await openManual(makeData({ controlsKm: false, controlsHours: false }))
    const form = wrapper.get('form[action="/mantenimiento/planes"][method="post"]')
    await form.get('select[name="equipo_id"]').setValue('9')
    await form.get('input[name="base_fecha"]').setValue('2026-08-15')
    await form.get('input[name="intervalo_dias"]').setValue('180')
    await form.get('input[name="anticipacion_dias"]').setValue('30')

    const preview = wrapper.get('[data-testid="manual-preview"]')
    expect(preview.text()).toContain('15/08/2026')
    expect(preview.text()).toContain('Próximo mantenimiento: 11/02/2027')
    expect(preview.text()).toContain('Avisar desde: 12/01/2027')
  })
})
