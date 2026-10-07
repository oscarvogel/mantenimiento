import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import AppSidebar from '../src/components/AppSidebar.vue'

const navigation = [
  { key: 'dashboard', label: 'Dashboard', href: '/dashboard', icon: 'dashboard' },
  { key: 'equipment', label: 'Equipos', href: '/mantenimiento/equipos', icon: 'equipos' },
  { key: 'quick-readings', label: 'Registrar km/horas', href: '/mantenimiento/lecturas/rapidas', icon: 'lecturas' },
  {
    key: 'reading-control',
    label: 'Control de lecturas',
    href: '/mantenimiento/lecturas/control',
    icon: 'clipboard-list',
  },
  { key: 'plans', label: 'Planes', href: '/mantenimiento/planes', icon: 'planes' },
]

describe('AppSidebar / Control de lecturas', () => {
  it('mantiene Control de lecturas como entrada principal visible', () => {
    const wrapper = mount(AppSidebar, { props: { navigation } })
    const link = wrapper.findAll('a').find((a) => a.text().includes('Control de lecturas'))

    expect(link).toBeDefined()
    expect(link.isVisible()).toBe(true)
    expect(link.attributes('href')).toContain('lecturas/control')
  })

  it('deja Registrar km/horas en Más opciones para reducir ruido visual', async () => {
    const wrapper = mount(AppSidebar, { props: { navigation } })
    expect(wrapper.find('a[href="/mantenimiento/lecturas/rapidas"]').isVisible()).toBe(false)
    await wrapper.get('button[aria-controls="secondary-navigation"]').trigger('click')
    expect(wrapper.get('button[aria-controls="secondary-navigation"]').attributes('aria-expanded')).toBe('true')
    expect(wrapper.find('a[href="/mantenimiento/lecturas/rapidas"]').isVisible()).toBe(true)
  })

  it('renderiza un icono para Control de lecturas', () => {
    const wrapper = mount(AppSidebar, { props: { navigation } })
    const link = wrapper.findAll('a').find((a) => a.text().includes('Control de lecturas'))

    expect(link.find('svg').exists()).toBe(true)
  })
})
