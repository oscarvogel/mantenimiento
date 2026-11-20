import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import ApplicationShell from './ApplicationShell.vue'

describe('ApplicationShell platform context', () => {
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

  it('reserves bottom space so the AI button does not cover the last actions', () => {
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

    expect(wrapper.find('button[aria-label="Abrir asistente IA"]').exists()).toBe(true)
    expect(wrapper.get('#main-content').classes()).toContain('pb-24')
  })
})
