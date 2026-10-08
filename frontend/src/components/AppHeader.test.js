import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import AppHeader from './AppHeader.vue'

const mountHeader = (homeUrl = null) => mount(AppHeader, {
  props: {
    user: { name: 'Operador', initials: 'OP', roleLabel: 'Técnico' },
    company: { name: 'Empresa', scopeLabel: 'Central' },
    notifications: { enabled: false },
    homeUrl,
  },
  global: {
    stubs: {
      AppNotificationBell: true,
      ThemeToggle: true,
    },
  },
})

describe('AppHeader', () => {
  it('offers the portal return link when the backend provides one', () => {
    const wrapper = mountHeader('/inicio')

    expect(wrapper.find('a[href="/inicio"]').attributes('aria-label')).toBe('Ir al Centro de mandos')
  })

  it('does not show a tenant portal link when the backend omits it', () => {
    const wrapper = mountHeader()

    expect(wrapper.find('a[aria-label="Ir al Centro de mandos"]').exists()).toBe(false)
  })
})
