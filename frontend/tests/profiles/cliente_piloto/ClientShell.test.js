import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import ClientShell from '../../../src/profiles/cliente_piloto/shell/ClientShell.vue'

describe('cliente_piloto shell', () => {
  it('labels the demo-only preview and keeps the portal-return contract', () => {
    const wrapper = mount(ClientShell, {
      props: {
        shell: {
          homeUrl: '/inicio', navigation: [], notifications: { enabled: false },
          moduleNavigation: [], logout: null, user: { name: 'Ana' }, company: { name: 'Demo' },
        },
      },
      slots: { default: '<p>Panel de mantenimiento</p>' },
      global: { stubs: { ApplicationShell: { props: ['shell'], template: '<div data-testid="shared-application-shell" :data-home-url="shell.homeUrl"><slot /></div>' } } },
    })

    expect(wrapper.text()).toContain('Prototipo de revisión')
    expect(wrapper.get('[data-testid="shared-application-shell"]').text()).toContain('Panel de mantenimiento')
    expect(wrapper.get('[data-testid="shared-application-shell"]').attributes('data-home-url')).toBe('/inicio')
  })
})
