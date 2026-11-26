import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import ApplicationShell from './ApplicationShell.vue'

describe('ApplicationShell platform context', () => {
  it('can expand the desktop rail while keeping the same authorized destinations', async () => {
    const wrapper = mount(ApplicationShell, {
      props: {
        shell: {
          user: { name: 'Juan Díaz', initials: 'JD', roleLabel: 'Administrador' },
          company: { name: 'Flota Demo', scopeLabel: 'Central', branches: [] },
          navigation: [
            { key: 'platform-home', label: 'Inicio', href: '/inicio', icon: 'platform-home', active: true },
            { key: 'module-maintenance', label: 'Mantenimiento', href: '/dashboard', icon: 'module-wrench' },
            { key: 'module-billing', label: 'Facturación', disabled: true, status: 'Próximamente' },
          ],
          notifications: { enabled: false },
          logout: null,
        },
      },
    })

    const toggle = wrapper.get('button[aria-controls="desktop-primary-navigation"]')
    const nav = () => wrapper.get('#desktop-primary-navigation')
    expect(toggle.attributes('aria-expanded')).toBe('false')
    expect(nav().get('a[href="/inicio"]').attributes('aria-current')).toBe('page')
    expect(nav().get('a[href="/dashboard"] [data-test="rail-tooltip"]').text()).toBe('Mantenimiento')
    expect(nav().text()).not.toContain('Facturación')

    await toggle.trigger('click')

    expect(toggle.attributes('aria-expanded')).toBe('true')
    expect(nav().get('a[href="/dashboard"]').text()).toBe('Mantenimiento')
    expect(nav().get('[aria-label="Facturación: Próximamente"]').attributes('aria-disabled')).toBe('true')
    const props = wrapper.props()
    wrapper.unmount()

    // Expandida tapa el contenido: la página siguiente arranca contraída.
    const nextPage = mount(ApplicationShell, { props })
    expect(nextPage.get('button[aria-controls="desktop-primary-navigation"]').attributes('aria-expanded')).toBe('false')
    nextPage.unmount()
  })

  it('expandir la barra no desplaza el contenido y se cierra con clic afuera o Escape', async () => {
    const wrapper = mount(ApplicationShell, {
      attachTo: document.body,
      props: {
        shell: {
          user: { name: 'Juan Díaz', initials: 'JD', roleLabel: 'Administrador' },
          company: { name: 'Flota Demo', scopeLabel: 'Central', branches: [] },
          navigation: [{ key: 'platform-home', label: 'Inicio', href: '/inicio', icon: 'platform-home', active: true }],
          notifications: { enabled: false },
          logout: null,
        },
      },
    })
    const toggle = () => wrapper.get('button[aria-controls="desktop-primary-navigation"]')
    const contentClass = wrapper.get('.workspace-content').classes().join(' ')
    const shellClass = wrapper.get('.workspace-shell').classes().join(' ')

    await toggle().trigger('click')
    expect(toggle().attributes('aria-expanded')).toBe('true')
    expect(wrapper.get('.workspace-content').classes().join(' ')).toBe(contentClass)
    expect(wrapper.get('.workspace-shell').classes().join(' ')).toBe(shellClass)
    expect(wrapper.get('.workspace-sidebar').classes()).toContain('shadow-sidebar')

    await wrapper.get('[data-test="sidebar-scrim"]').trigger('click')
    expect(toggle().attributes('aria-expanded')).toBe('false')

    await toggle().trigger('click')
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))
    await nextTick()
    expect(toggle().attributes('aria-expanded')).toBe('false')
    wrapper.unmount()
  })

  it('muestra en el encabezado el módulo en el que estás', () => {
    const shell = {
      user: { name: 'Juan Díaz', initials: 'JD' },
      company: { name: 'Flota Demo', scopeLabel: 'Central' },
      navigation: [{ key: 'work-orders', label: 'Órdenes de trabajo', href: '/mantenimiento/ordenes', active: true }],
      moduleNavigation: [
        { key: 'platform-home', label: 'Inicio', href: '/inicio' },
        { key: 'module-maintenance', label: 'Mantenimiento', href: '/dashboard', active: true },
      ],
      notifications: { enabled: false },
    }
    expect(mount(ApplicationShell, { props: { shell } }).get('[data-test="current-module"]').text()).toBe('Mantenimiento')
    expect(mount(ApplicationShell, { props: { shell: { ...shell, moduleNavigation: [] } } }).find('[data-test="current-module"]').exists()).toBe(false)
  })

  it('does not present the maintenance chatbot as a platform module', () => {
    const wrapper = mount(ApplicationShell, {
      props: {
        shell: {
          user: { name: 'Juan Díaz', initials: 'JD', roleLabel: 'Administrador' },
          company: { name: 'Flota Demo', scopeLabel: 'Central', branches: [] },
          navigation: [{ key: 'platform-home', label: 'Inicio', href: '/inicio', icon: 'platform-home', active: true }],
          notifications: { enabled: false },
          logout: null,
        },
      },
      slots: { default: '<p>Centro de mandos</p>' },
    })

    expect(wrapper.text()).toContain('Centro de mandos')
    expect(wrapper.find('button[aria-label="Abrir asistente IA"]').exists()).toBe(false)
  })

  it('places the assistant in the module header and reserves space for mobile tabs', async () => {
    const wrapper = mount(ApplicationShell, {
      props: {
        shell: {
          user: { name: 'Juan Díaz', initials: 'JD', roleLabel: 'Administrador' },
          company: { name: 'Flota Demo', scopeLabel: 'Central', branches: [] },
          navigation: [{ key: 'trips', label: 'Viajes', href: '/viajes', icon: 'trips', active: true }],
          notifications: { enabled: false },
          logout: null,
        },
      },
      slots: { default: '<p>Cierre comercial</p>' },
    })

    await nextTick()

    const launcher = wrapper.get('header #operations-assistant-launcher button[aria-label="Abrir asistente IA"]')
    expect(launcher.classes()).not.toContain('fixed')
    expect(wrapper.get('#main-content').classes()).toContain('pb-24')
    wrapper.unmount()
  })

  it('returns focus to the mobile control that opened the drawer when Escape closes it', async () => {
    const wrapper = mount(ApplicationShell, {
      attachTo: document.body,
      props: {
        shell: {
          user: { name: 'Juan Díaz', initials: 'JD' },
          company: { name: 'Flota Demo', scopeLabel: 'Central' },
          navigation: [{ key: 'quick-readings', label: 'Registrar km/horas', href: '/mantenimiento/lecturas/rapidas', active: true }],
          notifications: { enabled: false },
        },
      },
    })

    const opener = wrapper.get('.mobile-tabbar button[aria-controls="mobile-main-menu"]')
    await opener.trigger('click')
    expect(wrapper.get('#mobile-main-menu').attributes('aria-modal')).toBe('true')
    expect(wrapper.get('.workspace-content').element.hasAttribute('inert')).toBe(true)
    expect(document.body.classList.contains('overflow-hidden')).toBe(true)

    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))
    await nextTick()
    await nextTick()
    expect(opener.attributes('aria-expanded')).toBe('false')
    expect(document.body.classList.contains('overflow-hidden')).toBe(false)
    expect(document.activeElement).toBe(opener.element)
    await vi.waitFor(() => expect(wrapper.find('#mobile-main-menu').exists()).toBe(false))
    wrapper.unmount()
  })
})
