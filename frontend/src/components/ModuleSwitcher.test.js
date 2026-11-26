import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import ModuleSwitcher from './ModuleSwitcher.vue'

const modules = [
  ['platform-home', 'Inicio', '/inicio'],
  ['module-maintenance', 'Mantenimiento', '/dashboard'],
  ['module-trips', 'Viajes', '/viajes'],
  ['module-fuel', 'Combustible'],
  ['module-tires', 'Neumáticos'],
  ['module-billing', 'Facturación'],
  ['module-management', 'Gerencial', '/gerencial'],
  ['module-ai', 'Inteligencia Artificial'],
].map(([key, label, href], index) => ({
  key,
  label,
  href: href ?? null,
  active: index === 1,
  disabled: !href,
  status: href ? 'Operativo' : 'Próximamente',
}))

const render = (list = modules) => mount(ModuleSwitcher, { props: { modules: list, fallbackTitle: 'Mantenimiento' } })

describe('ModuleSwitcher', () => {
  it('ofrece volver al portal y nombra el módulo actual', () => {
    const wrapper = render()
    expect(wrapper.get('a[href="/inicio"]').text()).toContain('Volver al portal')
    expect(wrapper.get('summary').text()).toContain('Mantenimiento')
  })

  it('deja alcanzable cada módulo habilitado y muestra los futuros sin enlace', () => {
    const wrapper = render()
    expect(wrapper.find('a[href="/viajes"]').exists()).toBe(true)
    expect(wrapper.find('a[href="/gerencial"]').exists()).toBe(true)
    for (const module of modules.filter((item) => item.key !== 'platform-home')) {
      expect(wrapper.text()).toContain(module.label)
    }
    const future = wrapper.get('[aria-label="Combustible: Próximamente"]')
    expect(future.element.tagName).not.toBe('A')
  })

  it('sin módulos del servidor muestra el nombre de respaldo y no inventa el portal', () => {
    const wrapper = render([])
    expect(wrapper.text()).toBe('Mantenimiento')
    expect(wrapper.find('a').exists()).toBe(false)
  })
})
