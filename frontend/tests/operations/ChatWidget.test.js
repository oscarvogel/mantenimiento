import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ChatWidget from '../../src/pages/operations/components/ChatWidget.vue'

describe('ChatWidget', () => {
  beforeEach(() => {
    window.localStorage.clear()
    vi.clearAllMocks()
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
      ok: true,
      json: async () => ({ conversation: { id: 123 } }),
    }))
  })

  it('inicia conversaciones usando el prefijo del despliegue plano', async () => {
    const wrapper = mount(ChatWidget, {
      global: {
        stubs: {
          ChatMessage: true,
          ChatToolConfirm: true,
          ChatVoiceButton: true,
        },
      },
    })

    expect(wrapper.get('button[title="Abrir asistente IA"] img').attributes('src')).toContain('/assets/brand/chatbot/robot-fab.svg')

    await wrapper.get('button[title="Abrir asistente IA"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-testid="chat-robot"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="chat-robot"] img').attributes('alt')).toBe('')
    expect(fetch).toHaveBeenCalledWith(
      '/mantenimiento/chatbot/conversaciones',
      expect.objectContaining({ method: 'POST' }),
    )
  })
})
