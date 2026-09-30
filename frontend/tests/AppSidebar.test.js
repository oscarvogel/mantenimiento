import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import AppSidebar from '../src/components/AppSidebar.vue'

const navigation = [
  { key: 'dashboard', label: 'Dashboard', href: '/dashboard', icon: 'dashboard' },
  { key: 'equipment', label: 'Equipos', href: '/mantenimiento/equipos', icon: 'truck', active: true },
  { key: 'imports', label: 'Importaciones', href: '/mantenimiento/importaciones', icon: 'upload' },
  { key: 'reports', label: 'Reportes', href: '/reportes', icon: 'chart' },
  { key: 'masters-equipment', label: 'Maestros', href: '/mantenimiento/maestros/equipos', icon: 'equipment' },
  { key: 'masters-expirations', label: 'Tipos de vencimiento', href: '/mantenimiento/maestros/vencimientos', icon: 'calendar' },
  { key: 'branches', label: 'Sucursales', href: '/administracion/sucursales', icon: 'branches' },
]

describe('AppSidebar', () => {
  it('agrupa la navegación sin perder enlaces ni estado activo', () => {
    const wrapper = mount(AppSidebar, { props: { navigation } })

    expect(wrapper.findAll('nav section h2').map((node) => node.text())).toEqual([
      'Operación',
      'Gestión',
      'Maestros',
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

  /**
   * #353: una sola entrada al centro de Maestros y, al lado, la entrada
   * independiente de Tipos de vencimiento. El label llega desde AppShellPayload;
   * el sidebar no debe renombrar nada por su cuenta.
   */
  it('no duplica entradas de Maestros ni renombra labels por su cuenta', () => {
    const wrapper = mount(AppSidebar, { props: { navigation } })

    const labels = wrapper.findAll('nav a').map((node) => node.text().trim())
    expect(labels.filter((label) => label === 'Maestros')).toHaveLength(1)
    expect(labels).toContain('Tipos de vencimiento')
    expect(labels).not.toContain('Catálogos de equipos')

    // El enlace a vencimientos conserva su href propio: es la unica via para
    // un usuario con empleados.editar y sin equipos.editar.
    expect(wrapper.find('a[href="/mantenimiento/maestros/vencimientos"]').exists()).toBe(true)
  })
})
