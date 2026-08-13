import { mount } from '@vue/test-utils'
import { flushPromises } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import NotificationCenterPage from '../../src/pages/notifications/NotificationCenterPage.vue'

const data = {
  notifications: {
    unread: 1,
    total: 1,
    items: [{ id: 4, title: 'Plan vencido', summary: 'Camión 10 requiere atención', severity: 'CRITICA', url: '/planes/4', readAt: null }],
  },
  preferences: {},
  push: { enabled: false, publicKey: '' },
  csrf: { name: 'csrf_test', hash: 'token' },
  urls: { index: 'http://example.com/notificaciones', read: '/notificaciones/leer', readAll: '/notificaciones/leer-todas', preferences: '/perfil/notificaciones', subscribe: '/push', unsubscribe: '/push/eliminar', test: '/push/prueba' },
}

describe('NotificationCenterPage', () => {
  it('muestra el centro interno y no solicita permiso push automáticamente', () => {
    const requestPermission = vi.fn(() => Promise.resolve('granted'))
    vi.stubGlobal('Notification', { requestPermission })
    const wrapper = mount(NotificationCenterPage, { props: { data: { ...data, push: { enabled: true, publicKey: 'test-key' } } } })

    expect(wrapper.text()).toContain('Plan vencido')
    expect(wrapper.text()).toContain('1 sin leer')
    expect(wrapper.find('input[name="csrf_test"]').attributes('value')).toBe('token')
    expect(requestPermission).not.toHaveBeenCalled()
    vi.unstubAllGlobals()
  })

  it('explica cuando Web Push todavía no está configurado', async () => {
    Object.defineProperty(navigator, 'serviceWorker', { configurable: true, value: {} })
    vi.stubGlobal('PushManager', {})
    const wrapper = mount(NotificationCenterPage, { props: { data } })
    await flushPromises()
    expect(wrapper.get('button[type="button"]').attributes()).toHaveProperty('disabled')
    expect(wrapper.text()).toContain('Web Push todavía no está configurado')
    vi.unstubAllGlobals()
  })

  it('desactiva únicamente la suscripción del dispositivo con CSRF', async () => {
    const unsubscribe = vi.fn(() => Promise.resolve(true))
    const subscription = { endpoint: 'https://push.example/device', unsubscribe }
    const getSubscription = vi.fn(() => Promise.resolve(subscription))
    Object.defineProperty(navigator, 'serviceWorker', { configurable: true, value: { getRegistration: vi.fn(() => Promise.resolve({ pushManager: { getSubscription } })) } })
    vi.stubGlobal('PushManager', {})
    vi.stubGlobal('Notification', { permission: 'granted', requestPermission: vi.fn() })
    const fetchMock = vi.fn()
      .mockResolvedValueOnce({ ok: true, json: () => Promise.resolve({ sent: 1, csrf: { name: 'csrf_test', hash: 'rotated' } }) })
      .mockResolvedValueOnce({ ok: true, json: () => Promise.resolve({ ok: true, csrf: { name: 'csrf_test', hash: 'final' } }) })
    vi.stubGlobal('fetch', fetchMock)
    const wrapper = mount(NotificationCenterPage, { props: { data: { ...data, push: { enabled: true, publicKey: 'test-key' } } } })
    await flushPromises()

    const testPush = wrapper.findAll('button').find((button) => button.text().includes('Enviar push'))
    await testPush.trigger('click')
    await flushPromises()
    const disable = wrapper.findAll('button').find((button) => button.text().includes('Desactivar'))
    await disable.trigger('click')
    await flushPromises()

    expect(fetchMock).toHaveBeenNthCalledWith(2, '/push/eliminar', expect.objectContaining({
      method: 'POST', headers: expect.objectContaining({ 'X-CSRF-TOKEN': 'rotated' }),
    }))
    expect(JSON.parse(fetchMock.mock.calls[1][1].body)).toEqual({ endpoint: subscription.endpoint })
    expect(unsubscribe).toHaveBeenCalledOnce()
    expect(wrapper.text()).toContain('Inactivo en este dispositivo')
    vi.unstubAllGlobals()
  })
})
