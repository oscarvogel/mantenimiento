import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import AppHeader from './AppHeader.vue'

const mountHeader = (homeUrl = null, moduleNavigation = []) => mount(AppHeader, {
  props: {
    user: { name: 'Operador', initials: 'OP', roleLabel: 'Técnico' },
    company: { name: 'Empresa', scopeLabel: 'Central' },
    notifications: { enabled: false },
    homeUrl,
    moduleNavigation,
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

  it('shows authorized platform modules and keeps future modules visible without links', () => {
    const wrapper = mountHeader(null, [
      { key: 'platform-home', label: 'Inicio', href: '/inicio', active: false },
      { key: 'module-maintenance', label: 'Mantenimiento', href: '/dashboard', active: true, status: 'Operativo' },
      { key: 'module-trips', label: 'Viajes', href: null, disabled: true, status: 'Sin implementación aún' },
    ])

    const nav = wrapper.get('nav[aria-label="Módulos de la plataforma"]')
    expect(nav.find('a[href="/inicio"]').text()).toContain('Inicio')
    expect(nav.find('a[href="/dashboard"]').attributes('aria-current')).toBe('page')
    expect(nav.text()).toContain('Mantenimiento')
    expect(nav.text()).toContain('Viajes')
    expect(nav.find('[aria-label="Viajes: Sin implementación aún"]').exists()).toBe(true)
    expect(nav.find('a[aria-label="Viajes: Sin implementación aún"]').exists()).toBe(false)
  })

  it('wraps the complete module menu instead of clipping modules off-screen', () => {
    const modules = [
      ['platform-home', 'Inicio', '/inicio'],
      ['module-maintenance', 'Mantenimiento', '/dashboard'],
      ['module-trips', 'Viajes'],
      ['module-fuel', 'Combustible'],
      ['module-tires', 'Neumáticos'],
      ['module-billing', 'Facturación'],
      ['module-management', 'Gerencial'],
      ['module-reports', 'Reportes'],
      ['module-automation', 'Automatizaciones'],
      ['module-ai', 'Inteligencia Artificial'],
    ].map(([key, label, href], index) => ({
      key,
      label,
      href: href ?? null,
      active: index === 1,
      disabled: !href,
      status: href ? 'Operativo' : 'Sin implementación aún',
    }))
    const wrapper = mountHeader(null, modules)

    const nav = wrapper.get('nav[aria-label="Módulos de la plataforma"]')
    const list = nav.get('ul')
    expect(list.classes()).toContain('flex-wrap')
    expect(list.classes().some((className) => className.includes('overflow-x-auto'))).toBe(false)
    expect(nav.findAll('li')).toHaveLength(modules.length)
    expect(nav.text()).toContain('Inteligencia Artificial')
    expect(nav.text()).toContain('Sin implementación aún')
  })
})
