import { afterEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import AuditChangesPage from '../../src/pages/admin/AuditChangesPage.vue'

const apiUrl = '/mantenimiento/superadmin/auditoria'
const data = {
  apiUrl,
  title: 'Auditoría de cambios',
  subtitle: 'Altas, ediciones y permisos registrados en todas las empresas.',
}

describe('AuditChangesPage', () => {
  afterEach(() => vi.unstubAllGlobals())

  it('muestra el listado y el detalle con valores anteriores y nuevos', async () => {
    const fetchMock = vi.fn().mockResolvedValueOnce({
      ok: true,
      json: async () => ({
        data: [
          {
            id: 12,
            fecha: '2026-11-06 10:00:00',
            scope: 'EMPRESA',
            action: 'usuario.creado',
            entity: 'usuarios',
            entityId: '3',
            reason: 'Alta aprobada',
            ip: '127.0.0.1',
            userAgent: 'PHPUnit',
            requestId: 'req-1',
            before: null,
            after: { nombre: 'Ana' },
            company: { id: 7, name: 'Empresa Demo' },
            branch: null,
            actor: { id: 9, name: 'Admin', email: 'admin@example.test' },
            actorCompany: null,
          },
        ],
        pagination: { page: 1, perPage: 25, total: 1, pages: 1 },
      }),
    })
    vi.stubGlobal('fetch', fetchMock)

    const wrapper = mount(AuditChangesPage, { props: { data } })
    await flushPromises()

    expect(wrapper.text()).toContain('Empresa Demo')
    expect(wrapper.text()).toContain('Usuario creado')
    expect(fetchMock.mock.calls[0][0]).toBe(`${apiUrl}?page=1&perPage=25`)

    await wrapper.findAll('button').find((button) => button.text().includes('Usuario creado')).trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('Valores anteriores')
    expect(wrapper.text()).toContain('Valores nuevos')
    expect(wrapper.text()).toContain('Ana')
  })
})
