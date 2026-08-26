import { afterEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import RegisterReadingPage from './RegisterReadingPage.vue'

const data = () => ({
  csrf: { name: 'csrf_test', hash: 'HASH1' },
  recordedAtDefault: '2026-08-26T08:30',
  routes: { index: '/mantenimiento/lecturas/rapidas', submitRow: '/mantenimiento/lecturas/rapidas/fila' },
  filters: { q: '' },
  equipment: {
    items: [
      { id: 10, code: 'CAM-25', plate: 'AA123BB', typeName: 'Camión', branchName: 'Central', controlsKm: true, controlsHours: false, currentKm: 185000, currentHours: null, maintenance: { state: 'OK', primaryPlan: { critical: { value: 1580, unit: 'km' } } } },
      { id: 20, code: 'TR-04', plate: null, typeName: 'Tractor', branchName: 'Central', controlsKm: false, controlsHours: true, currentKm: null, currentHours: 8420, maintenance: { state: 'SIN_PLAN', primaryPlan: null } },
    ],
  },
})

afterEach(() => { vi.restoreAllMocks(); vi.unstubAllGlobals(); window.history.replaceState({}, '', '/') })

describe('RegisterReadingPage', () => {
  it('muestra un flujo individual y sólo el campo aplicable al equipo seleccionado', async () => {
    const wrapper = mount(RegisterReadingPage, { props: { data: data() } })
    expect(wrapper.text()).toContain('Registrar km/horas')
    await wrapper.findAll('button[type="button"]')[0].trigger('click')
    expect(wrapper.text()).toContain('Última lectura: 185.000 km')
    expect(wrapper.text()).toContain('Kilometraje actual')
    expect(wrapper.text()).not.toContain('Horómetro actual')
  })

  it('bloquea una lectura regresiva con un mensaje concreto', async () => {
    const wrapper = mount(RegisterReadingPage, { props: { data: data() } })
    await wrapper.findAll('button[type="button"]')[0].trigger('click')
    await wrapper.find('input[inputmode="numeric"]').setValue('184000')
    await wrapper.find('form.grid').trigger('submit')
    expect(wrapper.text()).toContain('no puede ser menor a 185.000 km')
  })

  it('reutiliza submitRow y muestra el impacto preventivo devuelto por backend', async () => {
    const payload = data()
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
      ok: true,
      json: async () => ({
        result: { success: true, currentKilometers: 185420, currentHours: null },
        maintenance: { state: 'PROXIMO', primaryPlan: { critical: { value: 580, unit: 'km' } } },
        csrf: { name: 'csrf_test', hash: 'HASH2' },
      }),
    }))
    const wrapper = mount(RegisterReadingPage, { props: { data: payload } })
    await wrapper.findAll('button[type="button"]')[0].trigger('click')
    await wrapper.find('input[inputmode="numeric"]').setValue('185420')
    await wrapper.find('form.grid').trigger('submit')
    await flushPromises()
    expect(fetch).toHaveBeenCalledTimes(1)
    expect(fetch.mock.calls[0][0]).toBe(payload.routes.submitRow)
    expect(wrapper.text()).toContain('Lectura registrada correctamente')
    expect(wrapper.text()).toContain('faltan 580 km')
  })

  it('conserva la carga masiva existente mediante modo=masivo', () => {
    window.history.replaceState({}, '', '/mantenimiento/lecturas/rapidas?modo=masivo')
    const wrapper = mount(RegisterReadingPage, { props: { data: data() } })
    expect(wrapper.text()).toContain('Lecturas rápidas')
  })
})
