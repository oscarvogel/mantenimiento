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

    await wrapper.get('button[title="Abrir asistente IA"]').trigger('click')
    await flushPromises()

    expect(fetch).toHaveBeenCalledWith(
      '/mantenimiento/mantenimiento/chatbot/conversaciones',
      expect.objectContaining({ method: 'POST' }),
    )
  })
})
