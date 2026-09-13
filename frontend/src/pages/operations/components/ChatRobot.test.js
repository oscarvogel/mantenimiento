import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import { afterEach, describe, expect, it } from 'vitest'
import ChatRobot from './ChatRobot.vue'
import { applyTheme } from '../../../ui/theme.js'

afterEach(() => {
  applyTheme('light')
})

describe('ChatRobot', () => {
  it.each(['fab', 'full'])('renderiza la variante %s como imagen decorativa accesible', (variant) => {
    const wrapper = mount(ChatRobot, { props: { variant } })
    const image = wrapper.get('img')

    expect(image.attributes('src')).toContain(`/assets/brand/chatbot/robot-${variant}.svg`)
    expect(image.attributes('alt')).toBe('')
    expect(wrapper.attributes('aria-hidden')).toBe('true')
  })

  it('expone el estado de pensamiento para animarlo sin cambiar la lógica del chat', () => {
    const wrapper = mount(ChatRobot, { props: { state: 'thinking' } })

    expect(wrapper.classes()).toContain('chat-robot--thinking')
    expect(wrapper.get('img').attributes('src')).toContain('/assets/brand/chatbot/robot-fab-thinking-light.svg')
  })

  it.each(['loading', 'success', 'error', 'offline'])('resuelve el asset visual del estado %s', (state) => {
    const wrapper = mount(ChatRobot, { props: { state } })

    expect(wrapper.classes()).toContain(`chat-robot--${state}`)
    expect(wrapper.get('img').attributes('src')).toContain(`/assets/brand/chatbot/robot-fab-${state}-light.svg`)
  })

  it('cambia el asset del estado cuando cambia el tema', async () => {
    const wrapper = mount(ChatRobot, { props: { variant: 'full', state: 'success' } })

    expect(wrapper.get('img').attributes('src')).toContain('robot-full-success-light.svg')

    applyTheme('dark')
    await nextTick()

    expect(wrapper.get('img').attributes('src')).toContain('robot-full-success-dark.svg')
  })
})
