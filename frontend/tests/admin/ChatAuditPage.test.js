import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import ChatAuditPage from '../../src/pages/admin/ChatAuditPage.vue'

const apiUrl = '/mantenimiento/mantenimiento/chatbot/auditoria'
const data = { apiUrl, title: 'Auditoría', subtitle: 'Conversaciones', showCompanyFilter: false }

describe('ChatAuditPage', () => {
  afterEach(() => vi.unstubAllGlobals())

  it('muestra el listado y abre el detalle usando el endpoint del backend', async () => {
    const fetchMock = vi.fn()
      .mockResolvedValueOnce({
        ok: true,
        json: async () => ({
          data: [{ id: 7, companyName: 'Empresa Demo', userName: 'Admin', title: null, messageCount: 1, updatedAt: '2026-08-26T10:00:00-03:00' }],
          pagination: { page: 1, pages: 1, total: 1 },
        }),
      })
      .mockResolvedValueOnce({
        ok: true,
        json: async () => ({
          data: { id: 7, title: null, companyName: 'Empresa Demo', userName: 'Admin', messageCount: 1, messages: [{ id: 8, role: 'user', content: 'Hola', createdAt: '2026-08-26T10:00:00-03:00' }] },
        }),
      })
    vi.stubGlobal('fetch', fetchMock)

    const wrapper = mount(ChatAuditPage, { props: { data } })
    await flushPromises()

    expect(wrapper.text()).toContain('#7')
    expect(fetchMock.mock.calls[0][0]).toBe(`${apiUrl}?page=1&perPage=25`)

    await wrapper.findAll("button").find((button) => button.text().includes("#7")).trigger("click")
    await flushPromises()

    expect(fetchMock.mock.calls[1][0]).toBe(`${apiUrl}/7`)
    expect(wrapper.text()).toContain('Hola')
  })
})