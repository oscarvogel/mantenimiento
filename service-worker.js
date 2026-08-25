self.addEventListener('install', () => self.skipWaiting())
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()))

self.addEventListener('push', (event) => {
  let data = { title: 'Mantenimiento', body: 'Tenés una nueva notificación.', url: './notificaciones' }
  try { data = { ...data, ...event.data.json() } } catch (_) {}
  event.waitUntil(self.registration.showNotification(data.title, {
    body: data.body,
    icon: './assets/pwa/icon.svg',
    badge: './assets/pwa/icon.svg',
    data: { url: data.url },
    tag: data.tag,
    renotify: false,
  }))
})

self.addEventListener('notificationclick', (event) => {
  event.notification.close()
  const requested = event.notification.data?.url || './notificaciones'
  const candidate = new URL(requested, self.registration.scope)
  const scope = new URL(self.registration.scope)
  const target = candidate.origin === scope.origin && candidate.pathname.startsWith(scope.pathname)
    ? candidate.href
    : new URL('./notificaciones', self.registration.scope).href

  event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(async (clients) => {
    const exact = clients.find((client) => client.url === target)
    if (exact) return exact.focus()

    const inApp = clients.find((client) => {
      try {
        const current = new URL(client.url)
        return current.origin === scope.origin && current.pathname.startsWith(scope.pathname)
      } catch (_) {
        return false
      }
    })

    if (inApp) {
      await inApp.navigate(target)
      return inApp.focus()
    }

    return self.clients.openWindow(target)
  }))
})
