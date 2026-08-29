import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import AppNotificationBell from '../../src/components/AppNotificationBell.vue'

const props = {
  enabled: true,
  summaryUrl: '/notificaciones/resumen',
  centerUrl: '/notificaciones',
}

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('AppNotificationBell', () => {
  it('carga el contador y muestra el resumen al abrir la campana', async () => {
    const fetchMock = vi.fn().mockResolvedValue({
      ok: true,
      json: () => Promise.resolve({
        unread: 2,
        items: [
          {
            id: 10,
            title: 'Mantenimiento vencido: CAM-014',
            summary: 'Cambio de aceite · criterios: kilómetros',
            severity: 'CRITICA',
            url: '/mantenimiento/planes?equipo_id=14',
            createdAt: '2026-08-25 18:10:00',
            readAt: null,
          },
        ],
      }),
    })
    vi.stubGlobal('fetch', fetchMock)

    const wrapper = mount(AppNotificationBell, { props })
    await flushPromises()

    expect(fetchMock).toHaveBeenCalledWith('/notificaciones/resumen', expect.objectContaining({ credentials: 'same-origin' }))
    expect(wrapper.get('button').attributes('aria-label')).toContain('2 notificaciones sin leer')
    expect(wrapper.text()).toContain('2')

    await wrapper.get('button').trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('Mantenimiento vencido: CAM-014')
    expect(wrapper.text()).toContain('Cambio de aceite')
    expect(wrapper.get('a[href="/notificaciones"]').text()).toBe('Ver todas')
    expect(wrapper.get('a[href="/mantenimiento/planes?equipo_id=14"]')).toBeTruthy()
  })

  it('no renderiza ni consulta el resumen sin permiso', async () => {
    const fetchMock = vi.fn()
    vi.stubGlobal('fetch', fetchMock)

    const wrapper = mount(AppNotificationBell, { props: { ...props, enabled: false } })
    await flushPromises()

    expect(wrapper.html()).toBe('<!--v-if-->')
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it('muestra error recuperable si falla el resumen', async () => {
    const fetchMock = vi.fn()
      .mockResolvedValueOnce({ ok: false, status: 500 })
      .mockResolvedValueOnce({ ok: true, json: () => Promise.resolve({ unread: 0, items: [] }) })
    vi.stubGlobal('fetch', fetchMock)

    const wrapper = mount(AppNotificationBell, { props })
    await flushPromises()
    await wrapper.get('button').trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('No se pudo cargar el resumen')
    await wrapper.findAll('button').find((button) => button.text().includes('Reintentar')).trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('No tenés notificaciones pendientes')
    expect(fetchMock).toHaveBeenCalledTimes(2)
  })
})
