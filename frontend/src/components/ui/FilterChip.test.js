import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import FilterChip from './FilterChip.vue'

describe('FilterChip', () => {
  it('con href es un enlace que marca el filtro activo', () => {
    const chip = mount(FilterChip, { props: { label: 'En proceso', count: 5, href: '/ordenes?estado=EN_PROCESO', active: true } })
    expect(chip.element.tagName).toBe('A')
    expect(chip.attributes('href')).toBe('/ordenes?estado=EN_PROCESO')
    expect(chip.attributes('aria-current')).toBe('true')
    expect(chip.text()).toBe('En proceso 5')
    expect(chip.classes()).toContain('bg-action')
  })

  it('sin href es un botón que avisa el clic y expone si está presionado', async () => {
    const chip = mount(FilterChip, { props: { label: 'Vencidos', count: 3 } })
    expect(chip.element.tagName).toBe('BUTTON')
    expect(chip.attributes('aria-pressed')).toBe('false')
    await chip.trigger('click')
    expect(chip.emitted('select')).toHaveLength(1)
  })
})
