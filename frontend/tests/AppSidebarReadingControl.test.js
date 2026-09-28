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
    href: '/mantenimiento/mantenimiento/lecturas/control',
    icon: 'clipboard-list',
  },
  { key: 'plans', label: 'Planes', href: '/mantenimiento/planes', icon: 'planes' },
]

describe('AppSidebar / Control de lecturas', () => {
  it('ubica "Control de lecturas" dentro del grupo "Operación"', () => {
    const wrapper = mount(AppSidebar, { props: { navigation } })
    const html = wrapper.html()

    expect(html).toContain('Operación')
    expect(html).toContain('Control de lecturas')

    // Antes caía en el grupo "Más" porque no estaba en ninguna definición.
    const mas = html.indexOf('>Más<')
    const control = html.indexOf('Control de lecturas')
    expect(mas === -1 || control < mas).toBe(true)
  })

  it('enlaza al endpoint de control de lecturas', () => {
    const wrapper = mount(AppSidebar, { props: { navigation } })
    const link = wrapper.findAll('a').find((a) => a.text().includes('Control de lecturas'))

    expect(link).toBeDefined()
    expect(link.attributes('href')).toContain('lecturas/control')
  })

  it('renderiza un icono para el item y no un marcador vacío', () => {
    const wrapper = mount(AppSidebar, { props: { navigation } })
    const link = wrapper.findAll('a').find((a) => a.text().includes('Control de lecturas'))

    expect(link.find('svg').exists()).toBe(true)
  })
})
