import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import CommandCenterPage from './CommandCenterPage.vue'

describe('CommandCenterPage', () => {
  it('shows only the modules supplied as available by the backend', () => {
    const wrapper = mount(CommandCenterPage, {
      props: {
        data: {
          modules: [{
            key: 'maintenance',
            label: 'Mantenimiento',
            description: 'Equipos, lecturas y trabajo de mantenimiento.',
            href: '/dashboard',
            status: 'Operativo',
          }],
        },
      },
    })

    expect(wrapper.findAll('a[href="/dashboard"]')).toHaveLength(1)
    expect(wrapper.text()).toContain('Mantenimiento')
    expect(wrapper.text()).toContain('Operativo')
    expect(wrapper.text()).not.toContain('Viajes')
    expect(wrapper.text()).not.toContain('Combustible')
    expect(wrapper.text()).not.toContain('Facturación')
  })

  it('does not invent metrics or activity when the module catalog is empty', () => {
    const wrapper = mount(CommandCenterPage, { props: { data: { modules: [] } } })

    expect(wrapper.text()).toContain('No hay módulos operativos disponibles')
    expect(wrapper.text()).not.toMatch(/\b\d+%|\$\s?\d|alertas recientes/i)
    expect(wrapper.findAll('a')).toHaveLength(0)
  })

  it('keeps global administration separate from tenant modules', () => {
    const wrapper = mount(CommandCenterPage, {
      props: { data: { modules: [], globalAdminUrl: '/superadmin' } },
    })

    expect(wrapper.find('a[href="/superadmin"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('Ir a Administración global')
    expect(wrapper.find('a[href="/dashboard"]').exists()).toBe(false)
  })
})
