import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import ChatRobot from './ChatRobot.vue'

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
  })
})
