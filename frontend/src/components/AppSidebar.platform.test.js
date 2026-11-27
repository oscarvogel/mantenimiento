import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import AppSidebar from './AppSidebar.vue'

describe('AppSidebar module navigation', () => {
  it('shows Inicio and the module catalog instead of Mantenimiento navigation', () => {
    const navigation = [
      { key: 'platform-home', label: 'Inicio', href: '/inicio', icon: 'platform-home', active: true },
      { key: 'module-maintenance', label: 'Mantenimiento', href: '/dashboard', icon: 'module-maintenance', active: false },
      { key: 'module-trips', label: 'Viajes', href: null, icon: 'module-trips', disabled: true, status: 'Próximamente', badge: null },
    ]
    const wrapper = mount(AppSidebar, { props: { navigation, logout: null } })

    const nav = wrapper.get('nav[aria-label="Navegación principal"]')
    expect(nav.find('a[href="/inicio"]').attributes('aria-current')).toBe('page')
    expect(nav.find('a[href="/dashboard"]').text()).toContain('Mantenimiento')
    expect(nav.text()).toContain('Viajes')
    expect(nav.find('[aria-label="Viajes: Próximamente"]').exists()).toBe(true)
    expect(nav.text()).toContain('Próximamente')
    expect(nav.text()).not.toContain('Dashboard')
    expect(nav.text()).not.toContain('Equipos')
    expect(nav.text()).not.toContain('Control de lecturas')
    expect(wrapper.find('[role="img"][aria-label="Vogel Consultoría"]').exists()).toBe(true)
    expect(wrapper.text()).not.toContain('Gestión de flota')
  })

  it('muestra la descripción solo expandida', () => {
    const navigation = [
      { key: 'platform-home', label: 'Inicio', href: '/inicio', icon: 'platform-home', active: true, description: 'Lo que pasa hoy en la flota' },
      { key: 'module-fuel', label: 'Combustible', href: '/combustible', icon: 'module-fuel', description: 'Cargas, consumo y faltantes' },
    ]

    const expanded = mount(AppSidebar, { props: { navigation, logout: null, collapsible: true, collapsed: false } })
    const link = expanded.get('a[href="/combustible"]')
    expect(link.text()).toContain('Combustible')
    expect(link.text()).toContain('Cargas, consumo y faltantes')

    const collapsed = mount(AppSidebar, { props: { navigation, logout: null, collapsible: true, collapsed: true } })
    const rail = collapsed.get('a[href="/combustible"]')
    expect(collapsed.text()).not.toContain('Cargas, consumo y faltantes')
    expect(rail.text()).toBe('Combustible')
  })

  it('la barra contraída se desplaza y el nombre del ícono flota fuera de ella', async () => {
    const navigation = [
      { key: 'platform-home', label: 'Inicio', href: '/inicio', icon: 'platform-home', active: true },
      { key: 'module-fuel', label: 'Combustible', href: '/combustible', icon: 'module-fuel' },
    ]
    const wrapper = mount(AppSidebar, { attachTo: document.body, props: { navigation, collapsible: true, collapsed: true } })
    const nav = wrapper.get('#desktop-primary-navigation')
    expect(nav.classes()).toContain('overflow-y-auto')
    expect(wrapper.find('[data-test="rail-tooltip"]').exists()).toBe(false)

    const row = nav.get('a[href="/combustible"]').element.closest('li')
    row.getBoundingClientRect = () => ({ top: 100, height: 44, left: 0, right: 72, bottom: 144, width: 72 })
    await row.dispatchEvent(new Event('mouseenter'))
    await wrapper.vm.$nextTick()
    const tip = wrapper.get('[data-test="rail-tooltip"]')
    expect(tip.text()).toBe('Combustible')
    expect(tip.classes()).toContain('fixed')
    expect(tip.attributes('style')).toContain('top: 122px')
    expect(wrapper.findAll('[data-test="rail-tooltip"]')).toHaveLength(1)

    await nav.trigger('scroll')
    expect(wrapper.find('[data-test="rail-tooltip"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('la barra contraída de un módulo muestra siempre los mismos íconos, aunque la página actual no esté entre ellos', () => {
    const item = (key, label, active = false) => ({ key, label, href: `/${key}`, active })
    const navigation = [
      item('dashboard', 'Resumen'), item('equipment', 'Equipos'), item('work-orders', 'Órdenes de trabajo'),
      item('plans', 'Planes'), item('suppliers', 'Proveedores', true),
    ]
    const wrapper = mount(AppSidebar, { props: { navigation, logout: null, collapsible: true, collapsed: true } })

    const hrefs = wrapper.get('#desktop-primary-navigation').findAll('a').map((link) => link.attributes('href'))
    expect(hrefs).toEqual(['/dashboard', '/work-orders', '/plans', '/equipment'])
  })

  it('oficina y campo tienen sus propios íconos fijos', () => {
    const item = (key) => ({ key, label: key, href: `/${key}` })
    const office = ['equipment', 'quick-readings', 'work-orders', 'work-requests', 'plans', 'dashboard', 'my-work', 'report-failure'].map(item)
    const rail = (navigation) => mount(AppSidebar, { props: { navigation, collapsible: true, collapsed: true } })
      .get('#desktop-primary-navigation').findAll('a').map((link) => link.attributes('href'))

    expect(rail(office)).toEqual(['/dashboard', '/work-orders', '/work-requests', '/plans', '/equipment', '/quick-readings'])
    expect(rail(office.filter((entry) => entry.key !== 'plans'))).toEqual(['/dashboard', '/my-work', '/report-failure', '/quick-readings', '/work-orders', '/equipment'])
  })

  it('en Viajes los íconos fijos siguen el orden del menú, sin Historial', () => {
    const keys = ['trips-operations-dashboard', 'trips-dashboard', 'trips-operations', 'trips-fuel-pending', 'trips-profitability', 'trips-commercial-closing', 'customer-accounts', 'trip-masters']
    const navigation = keys.map((key) => ({ key, label: key, href: `/${key}` }))
    const hrefs = mount(AppSidebar, { props: { navigation, collapsible: true, collapsed: true } })
      .get('#desktop-primary-navigation').findAll('a').map((link) => link.attributes('href'))
    expect(hrefs).toEqual(['/trips-operations-dashboard', '/trips-operations', '/trips-fuel-pending', '/trips-commercial-closing', '/customer-accounts', '/trips-profitability'])
  })

  it('el tema va al pie: contraída solo el botón con su nombre en el globo; expandida, con la palabra', async () => {
    const navigation = [{ key: 'dashboard', label: 'Resumen', href: '/dashboard' }]
    const collapsed = mount(AppSidebar, { attachTo: document.body, props: { navigation, collapsible: true, collapsed: true } })
    const footer = collapsed.get('[data-test="sidebar-theme"]')
    expect(footer.text()).toBe('')

    const button = footer.get('[data-theme-toggle]')
    button.element.getBoundingClientRect = () => ({ top: 600, height: 44, left: 0, right: 72, bottom: 644, width: 72 })
    await button.trigger('mouseenter')
    expect(collapsed.get('[data-test="rail-tooltip"]').text()).toBe(button.attributes('aria-label'))
    await button.trigger('click')
    expect(collapsed.find('[data-test="rail-tooltip"]').exists()).toBe(false)
    collapsed.unmount()

    const expanded = mount(AppSidebar, { props: { navigation, collapsible: true, collapsed: false } })
    expect(expanded.get('[data-test="sidebar-theme"]').text()).toBe('Tema')
    expect(mount(AppSidebar, { props: { navigation, mobile: true } }).find('[data-test="sidebar-theme"] [data-theme-toggle]').exists()).toBe(true)
  })

  it('todos los íconos de la barra son del mismo juego (Heroicons), sin SVG propios', () => {
    const item = (key, icon) => ({ key, label: key, href: `/${key}`, icon })
    const navigation = [item('dashboard', 'dashboard'), item('work-orders', 'wrench'), item('equipment', 'truck'), item('users', 'users')]
    document.body.dataset.baseUrl = '/' // en la app real está; los SVG propios se armaban con ella
    for (const collapsed of [false, true]) {
      const nav = mount(AppSidebar, { props: { navigation, collapsible: true, collapsed } }).get('#desktop-primary-navigation')
      expect(nav.findAll('img')).toHaveLength(0)
      expect(nav.findAll('a svg').length).toBe(nav.findAll('a').length)
    }
    delete document.body.dataset.baseUrl
  })

  it('no repite Cerrar sesión: vive en Mi cuenta', () => {
    const wrapper = mount(AppSidebar, { props: { navigation: [{ key: 'dashboard', label: 'Resumen', href: '/dashboard' }] } })
    expect(wrapper.text()).not.toContain('Cerrar sesión')
  })

  it('muestra el dashboard de viajes como primera opción del módulo', () => {
    const navigation = [
      { key: 'trips-operations-dashboard', label: 'Dashboard', href: '/viajes/operativos/dashboard', icon: 'chart', active: true },
      { key: 'trips-dashboard', label: 'Historial', href: '/viajes', icon: 'truck', active: false },
      { key: 'trips-operations', label: 'Viajes operativos', href: '/viajes/operativos', icon: 'clipboard-list', active: false },
      { key: 'trips-profitability', label: 'Rentabilidad', href: '/viajes/rentabilidad', icon: 'chart', active: false },
      { key: 'trip-masters', label: 'Maestros', href: '/viajes/maestros', icon: 'clipboard-list', active: false },
      { key: 'employees', label: 'Empleados', href: '/mantenimiento/empleados', icon: 'users', active: false },
    ]
    const wrapper = mount(AppSidebar, { props: { navigation, logout: null } })

    const nav = wrapper.get('nav[aria-label="Navegación principal"]')
    expect(nav.findAll('a')[0].text()).toContain('Dashboard')
    expect(nav.find('a[href="/viajes/operativos/dashboard"]').attributes('aria-current')).toBe('page')
    expect(nav.get('a[href="/mantenimiento/empleados"]').isVisible()).toBe(true)
    expect(nav.get('#nav-group-configuracion').find('a[href="/viajes/maestros"]').exists()).toBe(true)
  })

  it('shows a dedicated Gerencial menu without operational maintenance entries', () => {
    const navigation = [
      { key: 'managerial-cost-km', label: 'Costo teórico por km', href: '/gerencial/costo-teorico-km', icon: 'chart', active: true },
    ]
    const wrapper = mount(AppSidebar, { props: { navigation, logout: null } })

    const nav = wrapper.get('nav[aria-label="Navegación principal"]')
    expect(nav.find('a[href="/gerencial/costo-teorico-km"]').attributes('aria-current')).toBe('page')
    expect(nav.text()).toContain('Costo teórico por km')
    expect(nav.text()).not.toContain('Más opciones')
    expect(nav.text()).not.toContain('Equipos')
    expect(nav.text()).not.toContain('Mantenimiento')
    expect(wrapper.text()).toContain('Gerencial')
    expect(wrapper.text()).not.toContain('Gestión de flota')
  })
})
