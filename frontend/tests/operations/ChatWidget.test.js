import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

describe('ChatWidget', () => {
  beforeEach(() => {
    window.localStorage.clear()
    document.body.dataset.baseUrl = '/'
    vi.clearAllMocks()
    vi.resetModules()
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
      ok: true,
      json: async () => ({ conversation: { id: 123 } }),
    }))
  })

  const mountWidget = async (baseUrl) => {
    document.body.dataset.baseUrl = baseUrl
    const { default: ChatWidget } = await import('../../src/pages/operations/components/ChatWidget.vue')
    return mount(ChatWidget, {
      global: {
        stubs: {
          ChatMessage: true,
          ChatToolConfirm: true,
          ChatVoiceButton: true,
        },
      },
    })
  }

  it('usa base_url en entorno local', async () => {
    const wrapper = await mountWidget('/')

    expect(wrapper.get('button[title="Abrir asistente IA"] img').attributes('src')).toContain('/assets/brand/chatbot/robot-fab.svg')

    await wrapper.get('button[title="Abrir asistente IA"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-testid="chat-robot"]').exists()).toBe(true)
    expect(fetch).toHaveBeenCalledWith(
      '/mantenimiento/chatbot/conversaciones',
      expect.objectContaining({ method: 'POST' }),
    )
  })

  it('usa base_url del despliegue productivo en subdirectorio', async () => {
    const wrapper = await mountWidget('/mantenimiento/')

    await wrapper.get('button[title="Abrir asistente IA"]').trigger('click')
    await flushPromises()

    expect(fetch).toHaveBeenCalledWith(
      '/mantenimiento/mantenimiento/chatbot/conversaciones',
      expect.objectContaining({ method: 'POST' }),
    )
  })
  it('muestra una sola fila de acciones rapidas del briefing', async () => {
    vi.stubGlobal('fetch', vi.fn()
      .mockResolvedValueOnce({
        ok: true,
        json: async () => ({ conversation: { id: 123 } }),
      })
      .mockResolvedValue({
        ok: true,
        json: async () => ({
          briefing: {
            headline: 'Hay temas críticos que requieren atención',
            hasAttention: true,
            unread: 3,
            counts: { critical: 1, warning: 1, info: 1 },
            items: [],
            moreCount: 0,
            suggestions: ['Mostrame lo crítico', 'Ver vencimientos pendientes'],
          },
        }),
      }))

    const wrapper = await mountWidget('/')
    await wrapper.get('button[title="Abrir asistente IA"]').trigger('click')
    await flushPromises()

    expect(wrapper.findAll('[data-testid="chat-quick-actions"]')).toHaveLength(1)
    expect(wrapper.text().match(/Mostrame lo crítico/g) ?? []).toHaveLength(1)
    expect(wrapper.text().match(/Ver vencimientos pendientes/g) ?? []).toHaveLength(1)
  })

})
