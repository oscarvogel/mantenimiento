<script setup>
import { computed, onMounted, ref } from 'vue'
import { BellIcon, CheckCircleIcon, DevicePhoneMobileIcon } from '@heroicons/vue/24/outline'
import PaginationBar from '../operations/components/PaginationBar.vue'

const props = defineProps({ data: { type: Object, required: true } })
const activeTab = ref('inbox')
const selectedEventType = ref('preventivo.vencido')
const pushMessage = ref('')
const busy = ref(false)
const currentSubscription = ref(null)
const pushConfigured = computed(() => Boolean(props.data.push?.enabled && props.data.push?.publicKey))
const pushStatus = ref('Comprobando estado del dispositivo…')
const notificationPage = computed(() => props.data.notifications ?? { items: [], unread: 0, total: 0, page: 1, perPage: 10 })
const csrfState = ref({ ...(props.data.csrf ?? {}) })
const csrf = computed(() => csrfState.value)
const eventTypes = [
  ['preventivo.vencido', 'Preventivo vencido'], ['preventivo.proximo', 'Preventivo próximo'],
  ['orden.asignada', 'Orden asignada'], ['orden.demorada', 'Orden demorada'],
  ['solicitud.critica', 'Solicitud crítica'], ['equipo.sin_lectura', 'Equipo sin lectura'],
  ['garantia.proxima', 'Garantía próxima'],
]
const modes = [
  ['INMEDIATO', 'Inmediato'], ['RESUMEN', 'Resumen diario'], ['CRITICO', 'Solo críticos'], ['DESACTIVADO', 'Desactivado'],
]
const tabs = [
  ['inbox', 'Bandeja', BellIcon],
  ['preferences', 'Preferencias', CheckCircleIcon],
  ['devices', 'Dispositivos', DevicePhoneMobileIcon],
]
const selectedEvent = computed(() => eventTypes.find(([value]) => value === selectedEventType.value) ?? eventTypes[0])
const selectedPreferences = computed(() => props.data.preferences?.[selectedEventType.value] ?? {})

function csrfFields() { return { [csrf.value.name]: csrf.value.hash } }
function applyCsrf(response) { if (response?.csrf?.name && response?.csrf?.hash) csrfState.value = response.csrf }
function base64UrlToUint8Array(value) {
  const padding = '='.repeat((4 - value.length % 4) % 4)
  const raw = atob((value + padding).replace(/-/g, '+').replace(/_/g, '/'))
  return Uint8Array.from([...raw].map((char) => char.charCodeAt(0)))
}
function appScope() {
  const path = new URL(props.data.urls.index).pathname.replace(/\/notificaciones$/, '').replace(/\/$/, '')
  return path === '/' ? '' : path
}
function scopedPath(file = '') {
  const base = appScope()
  return `${base}/${file}`.replace(/\/+$/, '/')
}
async function refreshPushStatus() {
  if (!pushConfigured.value) { pushStatus.value = 'Web Push todavía no está configurado en el servidor.'; return }
  if (!('serviceWorker' in navigator) || !('PushManager' in window)) { pushStatus.value = 'Este navegador no admite Web Push.'; return }
  const registration = await navigator.serviceWorker.getRegistration(scopedPath())
  currentSubscription.value = await registration?.pushManager.getSubscription() ?? null
  pushStatus.value = currentSubscription.value
    ? 'Activo en este dispositivo.'
    : Notification.permission === 'denied' ? 'Permiso bloqueado en el navegador.' : 'Inactivo en este dispositivo.'
}
onMounted(() => { refreshPushStatus().catch(() => { pushStatus.value = 'No se pudo consultar el estado.' }) })
async function activatePush() {
  busy.value = true; pushMessage.value = ''
  try {
    if (!('serviceWorker' in navigator) || !('PushManager' in window)) throw new Error('Este navegador no admite Web Push.')
    if (!props.data.push?.enabled || !props.data.push?.publicKey) throw new Error('Web Push todavía no está configurado en el servidor.')
    const base = appScope()
    const registration = await navigator.serviceWorker.register(scopedPath('service-worker.js'), { scope: scopedPath() })
    const permission = await Notification.requestPermission()
    if (permission !== 'granted') throw new Error('El permiso no fue concedido.')
    const subscription = await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: base64UrlToUint8Array(props.data.push.publicKey) })
    const response = await fetch(props.data.urls.subscribe, {
      method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf.value.hash },
      body: JSON.stringify(subscription.toJSON()),
    })
    const result = await response.json(); applyCsrf(result)
    if (!response.ok) throw new Error(result.error || 'No se pudo guardar la suscripción.')
    currentSubscription.value = subscription
    pushStatus.value = 'Activo en este dispositivo.'
    pushMessage.value = 'Notificaciones activadas en este dispositivo.'
  } catch (error) { pushMessage.value = error.message } finally { busy.value = false }
}
async function deactivatePush() {
  busy.value = true; pushMessage.value = ''
  try {
    if (!currentSubscription.value) throw new Error('No hay una suscripción activa en este dispositivo.')
    const response = await fetch(props.data.urls.unsubscribe, {
      method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf.value.hash },
      body: JSON.stringify({ endpoint: currentSubscription.value.endpoint }),
    })
    const result = await response.json(); applyCsrf(result)
    if (!response.ok) throw new Error(result.error || 'No se pudo desactivar la suscripción.')
    await currentSubscription.value.unsubscribe()
    currentSubscription.value = null
    pushStatus.value = 'Inactivo en este dispositivo.'
    pushMessage.value = 'Notificaciones desactivadas en este dispositivo.'
  } catch (error) { pushMessage.value = error.message } finally { busy.value = false }
}
async function sendTestPush() {
  busy.value = true; pushMessage.value = ''
  try {
    const response = await fetch(props.data.urls.test, { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-TOKEN': csrf.value.hash } })
    const result = await response.json(); applyCsrf(result)
    if (!response.ok) throw new Error(result.error || 'No se pudo enviar la prueba.')
    pushMessage.value = result.sent > 0 ? 'Push de prueba enviado.' : 'No se entregó la prueba en ningún dispositivo.'
  } catch (error) { pushMessage.value = error.message } finally { busy.value = false }
}
</script>

<template>
  <section class="space-y-6">
    <header class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
      <div>
        <p class="text-sm font-semibold text-primary">Mi perfil</p>
        <h1 class="text-2xl font-bold text-ink sm:text-3xl">Notificaciones</h1>
        <p class="mt-1 text-sm text-ink-muted">Gestioná avisos, preferencias y dispositivos sin perder el foco.</p>
      </div>
      <form v-if="activeTab === 'inbox' && notificationPage.unread" :action="data.urls.readAll" method="post">
        <input v-for="(value, name) in csrfFields()" :key="name" type="hidden" :name="name" :value="value">
        <button class="min-h-11 rounded-lg border border-primary px-4 py-2 text-sm font-semibold text-primary hover:bg-primary-subtle">Marcar todas como leídas</button>
      </form>
    </header>

    <div class="overflow-x-auto border-b border-border" role="tablist" aria-label="Secciones de notificaciones">
      <div class="flex min-w-max gap-1">
        <button
          v-for="tab in tabs"
          :id="`notification-tab-${tab[0]}`"
          :key="tab[0]"
          type="button"
          role="tab"
          :aria-selected="activeTab === tab[0]"
          :aria-controls="`notification-panel-${tab[0]}`"
          class="inline-flex min-h-11 items-center gap-2 border-b-2 px-4 py-2.5 text-sm font-semibold transition"
          :class="activeTab === tab[0] ? 'border-primary text-primary' : 'border-transparent text-ink-muted hover:border-border-strong hover:text-ink'"
          @click="activeTab = tab[0]"
        >
          <component :is="tab[2]" class="size-5" aria-hidden="true" />
          {{ tab[1] }}
          <span v-if="tab[0] === 'inbox' && notificationPage.unread" class="rounded-full bg-danger-subtle px-2 py-0.5 text-xs text-danger-strong">{{ notificationPage.unread }}</span>
        </button>
      </div>
    </div>

    <section
      v-if="activeTab === 'inbox'"
      id="notification-panel-inbox"
      role="tabpanel"
      aria-labelledby="notification-tab-inbox"
      class="overflow-hidden rounded-xl border border-border bg-surface-raised"
    >
      <div class="border-b border-border-subtle px-4 py-4 sm:px-5">
        <h2 class="font-bold text-ink">Bandeja reciente</h2>
        <p class="mt-1 text-sm text-ink-muted">{{ notificationPage.unread }} sin leer de {{ notificationPage.total }} recientes.</p>
      </div>
      <div v-if="notificationPage.items.length" class="divide-y divide-border-subtle">
        <article v-for="item in notificationPage.items" :key="item.id" class="flex gap-3 p-4 sm:p-5" :class="!item.readAt && 'bg-primary-subtle/40'">
          <BellIcon class="mt-0.5 size-5 shrink-0 text-primary" aria-hidden="true" />
          <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-2"><h3 class="font-semibold text-ink">{{ item.title }}</h3><span class="rounded-full bg-surface-muted px-2 py-0.5 text-xs text-ink-muted">{{ item.severity }}</span></div>
            <p class="mt-1 text-sm text-ink-muted">{{ item.summary }}</p>
            <div class="mt-3 flex flex-wrap gap-3 text-sm"><a v-if="item.url" :href="item.url" class="font-semibold text-primary hover:underline">Abrir detalle</a><form v-if="!item.readAt" :action="`${data.urls.read}/${item.id}`" method="post"><input v-for="(value, name) in csrfFields()" :key="name" type="hidden" :name="name" :value="value"><button class="font-semibold text-ink-muted hover:text-primary">Marcar leída</button></form></div>
          </div>
        </article>
      </div>
      <div v-else class="p-10 text-center"><CheckCircleIcon class="mx-auto size-10 text-success"/><p class="mt-3 font-semibold text-ink">No hay notificaciones recientes</p></div>
      <PaginationBar :pagination="notificationPage.pagination ?? { page: 1, perPage: 10, total: 0, totalPages: 1 }" />
    </section>

    <section
      v-else-if="activeTab === 'preferences'"
      id="notification-panel-preferences"
      role="tabpanel"
      aria-labelledby="notification-tab-preferences"
      class="rounded-xl border border-border bg-surface-raised p-4 sm:p-6"
    >
      <div class="max-w-3xl">
        <h2 class="text-lg font-bold text-ink">Preferencias por evento</h2>
        <p class="mt-1 text-sm text-ink-muted">Editá un tipo de aviso por vez. La notificación interna siempre queda disponible como historial.</p>
        <label class="mt-5 grid gap-1.5 text-sm font-semibold text-ink">
          Tipo de aviso
          <select v-model="selectedEventType" class="min-h-11 rounded-lg border border-border-strong bg-white px-3 font-normal">
            <option v-for="event in eventTypes" :key="event[0]" :value="event[0]">{{ event[1] }}</option>
          </select>
        </label>
        <form :key="selectedEventType" :action="data.urls.preferences" method="post" class="mt-4 grid gap-4 rounded-lg border border-border-subtle bg-surface-subtle p-4 sm:grid-cols-2">
          <input v-for="(value, name) in csrfFields()" :key="name" type="hidden" :name="name" :value="value">
          <input type="hidden" name="event_type" :value="selectedEventType">
          <input type="hidden" name="internal" value="INMEDIATO">
          <div class="sm:col-span-2"><p class="font-semibold text-ink">{{ selectedEvent[1] }}</p><p class="text-xs text-ink-muted">Canal interno: siempre activo</p></div>
          <label v-for="channel in ['email','push']" :key="channel" class="grid gap-1.5 text-xs font-semibold uppercase tracking-wide text-ink-muted">
            {{ channel }}
            <select :name="channel" class="min-h-11 rounded-lg border border-border-strong bg-white px-3 text-sm font-normal normal-case text-ink">
              <option v-for="mode in modes" :key="mode[0]" :value="mode[0]" :selected="(selectedPreferences[channel] ?? (channel === 'email' ? 'RESUMEN' : 'CRITICO')) === mode[0]">{{ mode[1] }}</option>
            </select>
          </label>
          <div class="sm:col-span-2"><button class="min-h-11 w-full rounded-lg bg-primary px-4 py-2.5 text-sm font-semibold text-white hover:bg-primary-hover sm:w-auto">Guardar preferencia</button></div>
        </form>
      </div>
    </section>

    <section
      v-else
      id="notification-panel-devices"
      role="tabpanel"
      aria-labelledby="notification-tab-devices"
      class="rounded-xl border border-border bg-surface-raised p-4 sm:p-6"
    >
      <div class="flex items-start gap-3"><DevicePhoneMobileIcon class="size-6 shrink-0 text-primary"/><div><h2 class="text-lg font-bold text-ink">Notificaciones en este dispositivo</h2><p class="text-sm text-ink-muted">El permiso se solicita únicamente cuando elegís activarlas.</p></div></div>
      <p class="mt-4 rounded-lg bg-surface-muted px-4 py-3 text-sm font-semibold text-ink" role="status">{{ pushStatus }}</p>
      <div class="mt-4 grid gap-3 sm:flex sm:flex-wrap"><button type="button" class="min-h-11 rounded-lg bg-primary px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50" :disabled="busy || !pushConfigured || !!currentSubscription" @click="activatePush">Activar en este dispositivo</button><button type="button" class="min-h-11 rounded-lg border border-primary px-4 py-2.5 text-sm font-semibold text-primary disabled:opacity-50" :disabled="busy || !currentSubscription" @click="sendTestPush">Enviar push de prueba</button><button type="button" class="min-h-11 rounded-lg border border-danger px-4 py-2.5 text-sm font-semibold text-danger disabled:opacity-50" :disabled="busy || !currentSubscription" @click="deactivatePush">Desactivar este dispositivo</button></div>
      <p v-if="pushMessage" class="mt-3 text-sm text-ink-muted" role="status">{{ pushMessage }}</p>
    </section>
  </section>
</template>
