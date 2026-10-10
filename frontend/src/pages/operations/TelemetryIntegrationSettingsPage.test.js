import { afterEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import TelemetryIntegrationSettingsPage from './TelemetryIntegrationSettingsPage.vue'

const wrappers = []

const render = () => {
  const wrapper = mount(TelemetryIntegrationSettingsPage, {
    props: {
      data: {
        csrf: { name: 'csrf', hash: 'hash' },
        actions: { save: '/administracion/integraciones/telemetria' },
        integrations: [
          { provider: 'wialon', name: 'Wialon TSA', active: true, linkedEquipmentCount: 6, lastSuccess: null },
          { provider: 'wialon', name: 'Wialon otra cuenta', active: true, linkedEquipmentCount: 2, lastSuccess: null },
        ],
      },
    },
  })
  wrappers.push(wrapper)
  return wrapper
}

afterEach(() => wrappers.splice(0).forEach((wrapper) => wrapper.unmount()))

describe('TelemetryIntegrationSettingsPage', () => {
  it('permite elegir proveedor y pegar un token sin devolver las credenciales guardadas', () => {
    const wrapper = render()

    expect(wrapper.get('select[name="provider"] option:checked').element.value).toBe('wialon')
    expect(wrapper.get('textarea[name="token"]').element.value).toBe('')
    expect(wrapper.text()).toContain('Wialon TSA')
    expect(wrapper.text()).toContain('Wialon otra cuenta')
  })
})
