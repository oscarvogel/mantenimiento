import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import ModuleHeading from './ModuleHeading.vue'

const modules = [
  { key: 'platform-home', label: 'Inicio', href: '/inicio' },
  { key: 'module-maintenance', label: 'Mantenimiento', href: '/dashboard', active: true },
  { key: 'module-trips', label: 'Viajes', href: '/viajes' },
  { key: 'module-fuel', label: 'Combustible', href: null, disabled: true, status: 'Próximamente' },
]

const render = (list = modules) => mount(ModuleHeading, { props: { modules: list, fallbackTitle: 'Mantenimiento' } })

describe('ModuleHeading', () => {
  it('ofrece volver al portal y nombra el módulo actual', () => {
    const wrapper = render()
    expect(wrapper.get('a[href="/inicio"]').text()).toContain('Volver al portal')
    expect(wrapper.get('p').text()).toBe('Mantenimiento')
  })

  it('no cambia de módulo por su cuenta: eso se hace desde el portal', () => {
    const wrapper = render()
    expect(wrapper.findAll('a')).toHaveLength(1)
    expect(wrapper.find('details').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('Viajes')
    expect(wrapper.text()).not.toContain('Cambiar de módulo')
  })

  it('sin módulos del servidor muestra el nombre de respaldo y no inventa el portal', () => {
    const wrapper = render([])
    expect(wrapper.text()).toBe('Mantenimiento')
    expect(wrapper.find('a').exists()).toBe(false)
  })
})
