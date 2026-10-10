import { afterEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import TelemetryIntegrationSettingsPage from './TelemetryIntegrationSettingsPage.vue'

const wrappers = []

const render = () => {
  const wrapper = mount(TelemetryIntegrationSettingsPage, {
    props: {
      data: {
        csrf: { name: 'csrf', hash: 'hash' },
        actions: { save: '/administracion/integraciones/telemetria' },
        integrations: [
          { id: 9, provider: 'wialon', name: 'Wialon TSA', active: true, linkedEquipmentCount: 6, lastSuccess: null, unitsUrl: '/integraciones/9/unidades', saveLinksUrl: '/integraciones/9/vinculos' },
          { id: 10, provider: 'wialon', name: 'Wialon otra cuenta', active: true, linkedEquipmentCount: 2, lastSuccess: null, unitsUrl: '/integraciones/10/unidades', saveLinksUrl: '/integraciones/10/vinculos' },
        ],
      },
    },
  })
  wrappers.push(wrapper)
  return wrapper
}

afterEach(() => {
  wrappers.splice(0).forEach((wrapper) => wrapper.unmount())
  vi.unstubAllGlobals()
})

describe('TelemetryIntegrationSettingsPage', () => {
  it('permite elegir proveedor y pegar un token sin devolver las credenciales guardadas', () => {
    const wrapper = render()

    expect(wrapper.get('select[name="provider"] option:checked').element.value).toBe('wialon')
    expect(wrapper.get('textarea[name="token"]').element.value).toBe('')
    expect(wrapper.text()).toContain('Wialon TSA')
    expect(wrapper.text()).toContain('Wialon otra cuenta')
  })

  it('carga las unidades de una cuenta y ofrece vincularlas con equipos', async () => {
    const fetch = vi.fn().mockResolvedValue({
      ok: true,
      json: async () => ({
        integration: { id: 9, name: 'Wialon TSA', provider: 'wialon' },
        equipment: [{ id: 12, code: 'AB499OK', plate: 'AB499OK' }],
        units: [{ id: 'unit-4', name: 'SCANIA 360 AB499OK' }],
        suggestedLinks: { 12: 'unit-4' },
        links: {},
      }),
    })
    vi.stubGlobal('fetch', fetch)
    const wrapper = render()

    await wrapper.get('button[data-load-units="9"]').trigger('click')
    await flushPromises()

    expect(fetch).toHaveBeenCalledWith('/integraciones/9/unidades', expect.objectContaining({ credentials: 'same-origin' }))
    expect(wrapper.get('input[type="hidden"][name="mappings[12]"]').element.value).toBe('unit-4')
    expect(wrapper.text()).toContain('1 coincidencia por patente')
    expect(wrapper.get('input[role="combobox"]').element.value).toBe('SCANIA 360 AB499OK')
    expect(wrapper.text()).toContain('AB499OK')
    expect(wrapper.get('form[action="/integraciones/9/vinculos"]')).toBeTruthy()
  })

  it('permite buscar una unidad por patente dentro del nombre de Wialon', async () => {
    const fetch = vi.fn().mockResolvedValue({
      ok: true,
      json: async () => ({
        equipment: [{ id: 12, code: 'AB499OK', plate: 'AB499OK' }],
        units: [
          { id: 'unit-4', name: 'SCANIA 360 AB499OK' },
          { id: 'unit-5', name: 'VOLVO 460 AC532DD' },
        ],
        links: {},
        suggestedLinks: {},
      }),
    })
    vi.stubGlobal('fetch', fetch)
    const wrapper = render()

    await wrapper.get('button[data-load-units="9"]').trigger('click')
    await flushPromises()
    await wrapper.get('input[role="combobox"]').setValue('AB499OK')

    expect(wrapper.findAll('[role="option"]').map((option) => option.text())).toEqual(['SCANIA 360 AB499OK'])
    await wrapper.get('[role="option"]').trigger('click')
    expect(wrapper.get('input[type="hidden"][name="mappings[12]"]').element.value).toBe('unit-4')
  })

  it('asigna identificadores accesibles distintos a cada buscador de unidades', async () => {
    const fetch = vi.fn().mockResolvedValue({
      ok: true,
      json: async () => ({
        equipment: [
          { id: 12, code: 'CAM-01', plate: 'AB499OK' },
          { id: 13, code: 'CAM-02', plate: 'AC532DD' },
        ],
        units: [
          { id: 'unit-4', name: 'SCANIA 360 AB499OK' },
          { id: 'unit-5', name: 'VOLVO 460 AC532DD' },
        ],
        links: {},
        suggestedLinks: {},
      }),
    })
    vi.stubGlobal('fetch', fetch)
    const wrapper = render()

    await wrapper.get('button[data-load-units="9"]').trigger('click')
    await flushPromises()

    const controls = wrapper.findAll('input[role="combobox"]').map((input) => input.attributes('aria-controls'))
    expect(controls).toHaveLength(2)
    expect(new Set(controls).size).toBe(2)
    await Promise.all(wrapper.findAll('input[role="combobox"]').map((input) => input.trigger('focus')))
    expect(wrapper.findAll('[role="listbox"]').map((list) => list.attributes('id'))).toEqual(controls)
  })
})
