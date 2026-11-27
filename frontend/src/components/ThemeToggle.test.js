import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it } from 'vitest'
import ThemeToggle from './ThemeToggle.vue'
import { applyTheme } from '../ui/theme.js'

describe('ThemeToggle', () => {
  afterEach(() => applyTheme('light'))

  it('cambia de tema y pasa del sol a la luna', async () => {
    applyTheme('light')
    const wrapper = mount(ThemeToggle, { attachTo: document.body })
    const button = wrapper.get('[data-theme-toggle]')

    expect(button.attributes('aria-label')).toBe('Activar modo oscuro')
    expect(button.classes()).not.toContain('theme-toggle--toggled')

    await button.trigger('click')

    expect(document.documentElement.dataset.theme).toBe('dark')
    expect(button.attributes('aria-pressed')).toBe('true')
    expect(button.attributes('aria-label')).toBe('Activar modo claro')
    expect(button.classes()).toContain('theme-toggle--toggled')
    wrapper.unmount()
  })

  it('cada botón usa su propio recorte para no mezclar dibujos', () => {
    const first = mount(ThemeToggle)
    const second = mount(ThemeToggle)
    const firstId = first.get('clipPath').attributes('id')

    expect(firstId).not.toBe(second.get('clipPath').attributes('id'))
    expect(first.get('svg > g').attributes('clip-path')).toBe(`url(#${firstId})`)
  })
})
