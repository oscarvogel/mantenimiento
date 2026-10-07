import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import AppSidebar from '../src/components/AppSidebar.vue'

const navigation = [
  { key: 'dashboard', label: 'Dashboard', href: '/dashboard', icon: 'dashboard' },
  { key: 'equipment', label: 'Equipos', href: '/mantenimiento/equipos', icon: 'truck', active: true },
  { key: 'reading-control', label: 'Control de lecturas', href: '/mantenimiento/lecturas/control', icon: 'clipboard-list' },
  { key: 'maintenance', label: 'Mantenimiento', href: '/mantenimiento', icon: 'maintenance' },
  { key: 'plans', label: 'Planes preventivos', href: '/mantenimiento/planes', icon: 'plans' },
  { key: 'reports', label: 'Reportes', href: '/reportes', icon: 'chart' },
  { key: 'quick-readings', label: 'Registrar km/horas', href: '/mantenimiento/lecturas/rapidas', icon: 'readings' },
  { key: 'imports', label: 'Importaciones', href: '/mantenimiento/importaciones', icon: 'upload' },
  { key: 'masters-equipment', label: 'Maestros', href: '/mantenimiento/maestros/equipos', icon: 'equipment' },
  { key: 'masters-expirations', label: 'Tipos de vencimiento', href: '/mantenimiento/maestros/vencimientos', icon: 'calendar' },
  { key: 'branches', label: 'Sucursales', href: '/administracion/sucursales', icon: 'branches' },
]

describe('AppSidebar', () => {
  it('muestra sólo las seis entradas operativas principales al iniciar', () => {
    const wrapper = mount(AppSidebar, { props: { navigation } })

    const visibleLinks = wrapper.findAll('nav a').filter((node) => node.isVisible())
    expect(visibleLinks.map((node) => node.text().trim())).toEqual([
      'Dashboard',
      'Equipos',
      'Control de lecturas',
      'Mantenimiento',
      'Preventivos',
      'Reportes',
    ])
    expect(wrapper.get('button[aria-controls="secondary-navigation"]').text()).toContain('Más opciones')
    expect(wrapper.get('button[aria-controls="secondary-navigation"]').attributes('aria-expanded')).toBe('false')
    expect(wrapper.get('a[aria-current="page"]').text()).toContain('Equipos')
  })

  it('mantiene todas las entradas secundarias accesibles sin perder href', async () => {
    const wrapper = mount(AppSidebar, { props: { navigation } })

    await wrapper.get('button[aria-controls="secondary-navigation"]').trigger('click')

    expect(wrapper.get('button[aria-controls="secondary-navigation"]').attributes('aria-expanded')).toBe('true')
    expect(wrapper.find('a[href="/mantenimiento/lecturas/rapidas"]').isVisible()).toBe(true)
    expect(wrapper.find('a[href="/mantenimiento/importaciones"]').isVisible()).toBe(true)
    expect(wrapper.find('a[href="/mantenimiento/maestros/vencimientos"]').isVisible()).toBe(true)
    expect(wrapper.find('a[href="/administracion/sucursales"]').isVisible()).toBe(true)
  })

  it('abre automáticamente Más opciones cuando la ruta activa es secundaria', () => {
    const secondaryActive = navigation.map((item) => ({
      ...item,
      active: item.key === 'imports',
    }))
    const wrapper = mount(AppSidebar, { props: { navigation: secondaryActive } })

    expect(wrapper.get('button[aria-controls="secondary-navigation"]').attributes('aria-expanded')).toBe('true')
    expect(wrapper.get('a[aria-current="page"]').text()).toContain('Importaciones')
  })

  it('mantiene disponibles entradas desconocidas dentro de Más opciones', async () => {
    const wrapper = mount(AppSidebar, {
      props: {
        navigation: [{ key: 'custom', label: 'Personalizado', href: '/custom', icon: 'dashboard' }],
      },
    })

    expect(wrapper.find('a[href="/custom"]').isVisible()).toBe(false)
    await wrapper.get('button[aria-controls="secondary-navigation"]').trigger('click')
    expect(wrapper.find('a[href="/custom"]').isVisible()).toBe(true)
  })

  it('no duplica Maestros ni renombra Tipos de vencimiento', async () => {
    const wrapper = mount(AppSidebar, { props: { navigation } })
    await wrapper.get('button[aria-controls="secondary-navigation"]').trigger('click')

    const labels = wrapper.findAll('nav a').map((node) => node.text().trim())
    expect(labels.filter((label) => label === 'Maestros')).toHaveLength(1)
    expect(labels).toContain('Tipos de vencimiento')
    expect(wrapper.find('a[href="/mantenimiento/maestros/vencimientos"]').exists()).toBe(true)
  })
})
