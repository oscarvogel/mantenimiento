import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import AppHeader from './AppHeader.vue'

const mountHeader = (homeUrl = null, extra = {}) => mount(AppHeader, {
  props: {
    user: { name: 'Juan Díaz', initials: 'JD', roleLabel: 'Técnico' },
    company: { name: 'Empresa', scopeLabel: 'Central' },
    notifications: { enabled: false },
    homeUrl,
    ...extra,
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

  it('saluda con iniciales, nombre y empresa', () => {
    const wrapper = mountHeader()

    expect(wrapper.get('[data-test="user-avatar"]').text()).toBe('JD')
    expect(wrapper.text()).toContain('Hola, Juan')
    expect(wrapper.text()).toContain('Empresa · Central')
  })

  it('la campana solo con avisos habilitados', () => {
    expect(mountHeader().findComponent({ name: 'AppNotificationBell' }).exists()).toBe(false)
    expect(mountHeader(null, { notifications: { enabled: true, summaryUrl: '#', centerUrl: '#' } })
      .findComponent({ name: 'AppNotificationBell' }).exists()).toBe(true)
  })

  it('sin URL de búsqueda no hay buscador', () => {
    const wrapper = mount(AppHeader, {
      props: { user: { name: 'Juan', initials: 'J' }, company: { name: 'E', scopeLabel: 'C' } },
      slots: { search: '<input data-test="search-slot" />' },
      global: { stubs: { AppNotificationBell: true, ThemeToggle: true } },
    })
    expect(wrapper.find('[data-test="search-slot"]').exists()).toBe(false)

    const withSearch = mount(AppHeader, {
      props: { user: { name: 'Juan', initials: 'J' }, company: { name: 'E', scopeLabel: 'C' }, equipmentSearchUrl: '/buscar' },
      slots: { search: '<input data-test="search-slot" />' },
      global: { stubs: { AppNotificationBell: true, ThemeToggle: true } },
    })
    expect(withSearch.find('[data-test="search-slot"]').exists()).toBe(true)
  })
})
