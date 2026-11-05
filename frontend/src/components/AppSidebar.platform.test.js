import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import AppSidebar from './AppSidebar.vue'

describe('AppSidebar module navigation', () => {
  it('shows Inicio and the module catalog instead of Mantenimiento navigation', () => {
    const navigation = [
      { key: 'platform-home', label: 'Inicio', href: '/inicio', icon: 'platform-home', active: true },
      { key: 'module-maintenance', label: 'Mantenimiento', href: '/dashboard', icon: 'module-maintenance', active: false },
      { key: 'module-trips', label: 'Viajes', href: null, icon: 'module-trips', disabled: true, status: 'Sin implementación aún', badge: null },
    ]
    const wrapper = mount(AppSidebar, { props: { navigation, logout: null } })

    const nav = wrapper.get('nav[aria-label="Navegación principal"]')
    expect(nav.find('a[href="/inicio"]').attributes('aria-current')).toBe('page')
    expect(nav.find('a[href="/dashboard"]').text()).toContain('Mantenimiento')
    expect(nav.text()).toContain('Viajes')
    expect(nav.find('[aria-label="Viajes: Sin implementación aún"]').exists()).toBe(true)
    expect(nav.text()).toContain('Sin implementación aún')
    expect(nav.text()).not.toContain('Dashboard')
    expect(nav.text()).not.toContain('Equipos')
    expect(nav.text()).not.toContain('Control de lecturas')
    expect(wrapper.find('[role="img"][aria-label="Vogel Consultoría"]').exists()).toBe(true)
    expect(wrapper.text()).not.toContain('Gestión de flota')
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
    expect(wrapper.text()).toContain('Más opciones')
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
