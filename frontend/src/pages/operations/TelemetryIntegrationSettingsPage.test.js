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
        units: [{ id: 'unit-4', name: 'Unidad motor 1' }],
        links: {},
      }),
    })
    vi.stubGlobal('fetch', fetch)
    const wrapper = render()

    await wrapper.get('button[data-load-units="9"]').trigger('click')
    await flushPromises()

    expect(fetch).toHaveBeenCalledWith('/integraciones/9/unidades', expect.objectContaining({ credentials: 'same-origin' }))
    expect(wrapper.get('select[name="mappings[12]"] option[value="unit-4"]').text()).toBe('Unidad motor 1')
    expect(wrapper.text()).toContain('AB499OK')
    expect(wrapper.get('form[action="/integraciones/9/vinculos"]')).toBeTruthy()
  })
})
