import { describe, expect, it } from 'vitest'
import { markRaw } from 'vue'
import { mount } from '@vue/test-utils'
import PageHost from '../src/PageHost.vue'
import ApplicationShell from '../src/components/ApplicationShell.vue'
import { loadFrontendProfile } from '../src/profiles/registry.js'
import { normalizeAppShellPayload } from '../src/adapters/dashboardPayload.js'

describe('profile based page composition', () => {
  it('normalizes the server profile and wraps the resolved page in that profile shell', async () => {
    const shell = { template: '<div data-testid="profile-shell"><slot /></div>' }
    const page = { template: '<p>Perfil Vogel</p>' }
    const profile = {
      key: 'vogel',
      shellComponent: shell,
      resolvePage: (pageKey, defaults) => defaults[pageKey],
    }
    const normalized = normalizeAppShellPayload({ presentation: { profile: 'vogel' } })
    const pageComponent = profile.resolvePage('report', { report: page })
    const wrapper = mount(PageHost, {
      props: { shell: normalized, shellComponent: markRaw(profile.shellComponent), pageComponent: markRaw(pageComponent), pageProps: {} },
    })

    expect(normalized.presentation.profile).toBe('vogel')
    expect(wrapper.get('[data-testid="profile-shell"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('Perfil Vogel')
  })

  it('keeps login payloads and unknown profiles on the base profile', async () => {
    expect(normalizeAppShellPayload({ presentation: { profile: '../../x' } }).presentation.profile).toBe('base')
    await expect(loadFrontendProfile('unknown')).resolves.toMatchObject({ key: 'base' })
  })

  it('renders the shared base shell through its static composition path', () => {
    const page = { template: '<p>Centro de mandos</p>' }
    const wrapper = mount(PageHost, {
      props: {
        shell: normalizeAppShellPayload({ presentation: { profile: 'platform' } }),
        shellComponent: markRaw(ApplicationShell),
        pageComponent: markRaw(page),
      },
    })

    expect(wrapper.findComponent(ApplicationShell).exists()).toBe(true)
    expect(wrapper.get('#main-content').text()).toContain('Centro de mandos')
  })
})
