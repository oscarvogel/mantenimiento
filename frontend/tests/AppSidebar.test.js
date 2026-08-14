import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import AppSidebar from '../src/components/AppSidebar.vue'

const navigation = [
  { key: 'dashboard', label: 'Dashboard', href: '/dashboard', icon: 'dashboard' },
  { key: 'equipment', label: 'Equipos', href: '/mantenimiento/equipos', icon: 'truck', active: true },
  { key: 'imports', label: 'Importaciones', href: '/mantenimiento/importaciones', icon: 'upload' },
  { key: 'reports', label: 'Reportes', href: '/reportes', icon: 'chart' },
  { key: 'branches', label: 'Sucursales', href: '/administracion/sucursales', icon: 'branches' },
]

describe('AppSidebar', () => {
  it('agrupa la navegación sin perder enlaces ni estado activo', () => {
    const wrapper = mount(AppSidebar, { props: { navigation } })

    expect(wrapper.findAll('nav section h2').map((node) => node.text())).toEqual([
      'Operación',
      'Gestión',
      'Administración',
    ])
    expect(wrapper.findAll('nav a')).toHaveLength(navigation.length)
    expect(wrapper.get('a[aria-current="page"]').text()).toContain('Equipos')
  })

  it('mantiene disponibles las entradas desconocidas en un grupo adicional', () => {
    const wrapper = mount(AppSidebar, {
      props: {
        navigation: [{ key: 'custom', label: 'Personalizado', href: '/custom', icon: 'dashboard' }],
      },
    })

    expect(wrapper.get('nav section h2').text()).toBe('Más')
    expect(wrapper.get('nav a').attributes('href')).toBe('/custom')
  })
})
