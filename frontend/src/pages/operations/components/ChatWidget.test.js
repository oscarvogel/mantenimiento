import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ChatWidget from './ChatWidget.vue'

const stubs = {
  ChatMessage: true,
  ChatToolConfirm: true,
  ChatVoiceButton: true,
}

describe('ChatWidget', () => {
  beforeEach(() => {
    window.localStorage.clear()
    vi.clearAllMocks()
  })

  it('muestra un aviso accionable cuando la empresa no tiene IA habilitada', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
      ok: false,
      status: 403,
    }))
    const wrapper = mount(ChatWidget, { global: { stubs } })

    await wrapper.get('button[title="Abrir asistente IA"]').trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('IA no habilitada')
    expect(wrapper.text()).toContain('Contactá al administrador de tu empresa')
  })
})
