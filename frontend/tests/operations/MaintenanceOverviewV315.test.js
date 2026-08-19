import { afterEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
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

const openCloseModal = async (data) => {
  const wrapper = mount(MaintenanceOverviewPage, { props: { data } })
  wrappers.push(wrapper)
  const closeButton = wrapper.findAll('button').find((button) => button.text().includes('Cerrar orden'))
  await closeButton.trigger('click')
  return document.body.querySelector('[role="dialog"][aria-labelledby="work-order-closure-title"]')
}

afterEach(() => wrappers.splice(0).forEach((wrapper) => wrapper.unmount()))

describe('V3.1.5 · cierre de orden de trabajo', () => {
  it('muestra solo kilometraje, conserva POST/CSRF y usa lenguaje de cierre', async () => {
    const modal = await openCloseModal(makeData({ controlsKm: true, controlsHours: false }))
    const form = modal.querySelector('form')

    expect(form.querySelector('input[name="km_salida"]')).not.toBeNull()
    expect(form.querySelector('input[name="horas_salida"]')).toBeNull()
    expect(form.textContent).toContain('Actual: 10.000 km')
    expect(form.textContent).not.toContain('Horómetro')
    expect(form.querySelector('input[name="csrf_test_name"]').value).toBe('secure-token')
    expect(form.textContent).toContain('Confirmar cierre de orden')
    expect(form.textContent).not.toContain('Trabajo realizado')
  })

  it('muestra solo horómetro, delta positivo y retroceso', async () => {
    const modal = await openCloseModal(makeData({ controlsKm: false, controlsHours: true }))
    const form = modal.querySelector('form')
    const hours = form.querySelector('input[name="horas_salida"]')

    expect(form.querySelector('input[name="km_salida"]')).toBeNull()
    expect(form.textContent).toContain('Actual: 5.240,5 h')
    hours.value = '5247.8'
    hours.dispatchEvent(new Event('input', { bubbles: true }))
    await nextTick()
    expect(form.textContent).toContain('+7,3 h')

    hours.value = '5239.9'
    hours.dispatchEvent(new Event('input', { bubbles: true }))
    await nextTick()
    expect(form.textContent).toContain('El valor es menor al último registro.')
  })

  it('muestra ambas lecturas y conserva los nombres de cierre esperados', async () => {
    const modal = await openCloseModal(makeData({ controlsKm: true, controlsHours: true }))
    const form = modal.querySelector('form')

    expect(form.querySelector('input[name="km_salida"]')).not.toBeNull()
    expect(form.querySelector('input[name="horas_salida"]')).not.toBeNull()
    expect(form.textContent).toContain('Nueva lectura de kilometraje')
    expect(form.textContent).toContain('Nueva lectura de horómetro')
    expect(form.querySelector('textarea[name="trabajo_realizado"]')).toBeNull()
    expect(form.querySelector('select[name="trabajo_realizado[1][resultado]"]')).not.toBeNull()
    expect(form.querySelector('textarea[name="trabajo_realizado[1][detalle]"]')).not.toBeNull()
    expect(form.textContent).toContain('Pendiente / no realizada')
    expect(form.textContent).toContain('No aplica')
    expect(form.querySelector('input[name="fecha_servicio"]')).not.toBeNull()
  })

  it('solo exige detalle cuando la tarea no fue realizada', async () => {
    const modal = await openCloseModal(makeData({ controlsKm: true, controlsHours: false }))
    const form = modal.querySelector('form')
    const result = form.querySelector('select[name="trabajo_realizado[1][resultado]"]')
    const detail = form.querySelector('textarea[name="trabajo_realizado[1][detalle]"]')

    expect(detail.required).toBe(false)
    result.value = 'PENDIENTE'
    result.dispatchEvent(new Event('change', { bubbles: true }))
    await nextTick()
    expect(detail.required).toBe(true)
    result.value = 'NO_APLICA'
    result.dispatchEvent(new Event('change', { bubbles: true }))
    await nextTick()
    expect(detail.required).toBe(true)
    result.value = 'REALIZADA'
    result.dispatchEvent(new Event('change', { bubbles: true }))
    await nextTick()
    expect(detail.required).toBe(false)
  })
})
