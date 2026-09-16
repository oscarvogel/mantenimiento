import { enableAutoUnmount, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import ChatRobot from './ChatRobot.vue'
import { applyTheme } from '../../../ui/theme.js'

enableAutoUnmount(afterEach)
beforeEach(() => {
  vi.stubGlobal('matchMedia', () => ({ matches: true, addEventListener() {}, removeEventListener() {} }))
})
afterEach(() => { applyTheme('light'); vi.unstubAllGlobals() })

describe('ChatRobot', () => {
  it.each(['fab', 'full'])('renderiza %s inline como decorativo, sin raster ni elementos enfocables', (variant) => {
    const wrapper = mount(ChatRobot, { props: { variant } })
    expect(wrapper.find('img').exists()).toBe(false)
    expect(wrapper.find('image').exists()).toBe(false)
    expect(wrapper.get('svg').attributes('focusable')).toBe('false')
    expect(wrapper.attributes('aria-hidden')).toBe('true')
  })

  it('permite varios robots sin referencias de degradados cruzadas', () => {
    const host = mount({ components: { ChatRobot }, template: '<div><ChatRobot /><ChatRobot variant="full" /></div>' })
    const [first, second] = host.findAllComponents(ChatRobot)
    const ids = [...first.findAll('[id]'), ...second.findAll('[id]')].map((node) => node.attributes('id'))
    expect(new Set(ids).size).toBe(ids.length)
    for (const wrapper of [first, second]) {
      for (const node of wrapper.findAll('[fill^="url"]')) {
        const id = node.attributes('fill').slice(5, -1)
        expect(wrapper.find(`[id="${id}"]`).exists()).toBe(true)
      }
    }
  })

  it.each(['fab', 'full'].flatMap((variant) =>
    ['thinking', 'loading', 'success', 'error', 'offline'].map((state) => [variant, state]),
  ))('%s comunica %s aunque el movimiento esté desactivado', async (variant, state) => {
    const wrapper = mount(ChatRobot, { props: { variant } })
    const idleEye = wrapper.get('[data-part="eye-left"] path').attributes('d')
    await wrapper.setProps({ state })
    expect(wrapper.get('svg').attributes('data-state')).toBe(state)
    expect(wrapper.get('[data-part="eye-left"] path').attributes('d')).not.toBe(idleEye)
    expect(wrapper.find('[data-part="status-symbol"]').exists()).toBe(true)
    await wrapper.setProps({ state: 'idle' })
    expect(wrapper.find('[data-part="status-symbol"]').exists()).toBe(false)
    expect(wrapper.get('[data-part="eye-left"] path').attributes('d')).toBe(idleEye)
  })

  it('conserva el estado del completo al cambiar tema y admite cambiar de variante', async () => {
    const wrapper = mount(ChatRobot, { props: { variant: 'full', state: 'success' } })
    expect(wrapper.get('svg').attributes('data-state')).toBe('success')
    expect(wrapper.find('[data-part="clipboard-assembly"] [data-part="hand-right"]').exists()).toBe(true)
    applyTheme('dark')
    expect(wrapper.get('svg').attributes('data-state')).toBe('success')
    await wrapper.setProps({ variant: 'fab' })
    expect(wrapper.find('[data-part="body"]').exists()).toBe(false)
    expect(wrapper.get('svg').attributes('data-state')).toBe('success')
    await wrapper.setProps({ variant: 'full' })
    expect(wrapper.find('[data-part="body"]').exists()).toBe(true)
  })
})
