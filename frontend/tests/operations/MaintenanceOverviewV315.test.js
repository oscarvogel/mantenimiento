import { afterEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import MaintenanceOverviewPage from '../../src/pages/operations/MaintenanceOverviewPage.vue'
import { maintenanceData } from './fixtures.js'

const wrappers = []

const makeData = (capabilities = {}) => {
  const data = JSON.parse(JSON.stringify(maintenanceData))
  data.orders = [{
    ...data.orders[0],
    controlsKm: true,
    controlsHours: true,
    currentKm: 10000,
    currentHours: '5240.5',
    ...capabilities,
  }]
  return data
}

const openCloseForm = async (data) => {
  const wrapper = mount(MaintenanceOverviewPage, { props: { data } })
  wrappers.push(wrapper)
  await wrapper.get('button[aria-controls="close-order-4"]').trigger('click')
  return wrapper.get('form[action="/mantenimiento/ordenes/4/cerrar"]')
}

afterEach(() => wrappers.splice(0).forEach((wrapper) => wrapper.unmount()))

describe('V3.1.5 · cierre de orden de trabajo', () => {
  it('muestra solo kilometraje, conserva POST/CSRF y usa lenguaje de cierre', async () => {
    const form = await openCloseForm(makeData({ controlsKm: true, controlsHours: false }))

    expect(form.find('input[name="km_salida"]').exists()).toBe(true)
    expect(form.find('input[name="horas_salida"]').exists()).toBe(false)
    expect(form.text()).toContain('Actual: 10.000 km')
    expect(form.text()).not.toContain('Horómetro')
    expect(form.find('input[name="csrf_test_name"]').attributes('value')).toBe('secure-token')
    expect(form.text()).toContain('Cerrar orden')
    expect(form.text()).not.toContain('Cerrar orden y recalcular')
    expect(form.text()).toContain('Al cerrar la orden se actualizarán las lecturas y el próximo mantenimiento automáticamente.')
  })

  it('muestra solo horómetro, delta positivo y retroceso', async () => {
    const form = await openCloseForm(makeData({ controlsKm: false, controlsHours: true }))
    const hours = form.get('input[name="horas_salida"]')

    expect(form.find('input[name="km_salida"]').exists()).toBe(false)
    expect(form.text()).toContain('Actual: 5.240,5 h')
    await hours.setValue('5247.8')
    expect(form.text()).toContain('+7,3 h')

    await hours.setValue('5239.9')
    expect(form.text()).toContain('El valor es menor al último registro.')
  })

  it('muestra ambas lecturas y conserva los nombres de cierre esperados', async () => {
    const form = await openCloseForm(makeData({ controlsKm: true, controlsHours: true }))

    expect(form.find('input[name="km_salida"]').exists()).toBe(true)
    expect(form.find('input[name="horas_salida"]').exists()).toBe(true)
    expect(form.text()).toContain('Nueva lectura de kilometraje')
    expect(form.text()).toContain('Nueva lectura de horómetro')
    expect(form.find('textarea[name="trabajo_realizado"]').exists()).toBe(true)
    expect(form.find('input[name="fecha_servicio"]').exists()).toBe(true)
  })
})
