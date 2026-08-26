import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import UsersAdminWithAuditPage from '../../src/pages/admin/UsersAdminWithAuditPage.vue'
import { usersAdminData } from './fixtures.js'

describe('historial tenant del chatbot', () => {
  beforeEach(() => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
      ok: true,
      json: async () => ({
        items: [],
        pagination: { page: 1, pages: 0, total: 0, perPage: 25 },
      }),
    }))
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('abre la auditoría sin permitir seleccionar otra empresa', async () => {
    const wrapper = mount(UsersAdminWithAuditPage, { props: { data: usersAdminData } })

    await wrapper.get('button:nth-of-type(2)').trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('Historial del chatbot')
    expect(wrapper.text()).toContain(usersAdminData.company.name)
    expect(wrapper.find('input[placeholder="ID empresa"]').exists()).toBe(false)
    expect(fetch).toHaveBeenCalledOnce()
    expect(String(fetch.mock.calls[0][0])).toBe("/mantenimiento/mantenimiento/chatbot/auditoria?page=1&perPage=25")
  })
})
