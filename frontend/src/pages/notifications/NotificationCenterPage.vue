<script setup>
import { computed, onMounted, ref } from 'vue'
import { BellIcon, CheckCircleIcon, DevicePhoneMobileIcon } from '@heroicons/vue/24/outline'
import PaginationBar from '../operations/components/PaginationBar.vue'

const props = defineProps({ data: { type: Object, required: true } })
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

function csrfFields() { return { [csrf.value.name]: csrf.value.hash } }
function applyCsrf(response) { if (response?.csrf?.name && response?.csrf?.hash) csrfState.value = response.csrf }
function base64UrlToUint8Array(value) {
  const padding = '='.repeat((4 - value.length % 4) % 4)
  const raw = atob((value + padding).replace(/-/g, '+').replace(/_/g, '/'))
  return Uint8Array.from([...raw].map((char) => char.charCodeAt(0)))
}
function appScope() {
  return new URL(props.data.urls.index).pathname.replace(/\/notificaciones$/, '') || '/'
}
async function refreshPushStatus() {
  if (!pushConfigured.value) { pushStatus.value = 'Web Push todavía no está configurado en el servidor.'; return }
  if (!('serviceWorker' in navigator) || !('PushManager' in window)) { pushStatus.value = 'Este navegador no admite Web Push.'; return }
  const registration = await navigator.serviceWorker.getRegistration(`${appScope()}/`)
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
    const registration = await navigator.serviceWorker.register(`${base}/service-worker.js`, { scope: `${base}/` })
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
      <div><p class="text-sm font-semibold text-primary">Mi perfil</p><h1 class="text-2xl font-bold text-ink sm:text-3xl">Notificaciones</h1><p class="mt-1 text-sm text-ink-muted">{{ notificationPage.unread }} sin leer de {{ notificationPage.total }} recientes.</p></div>
      <form v-if="notificationPage.unread" :action="data.urls.readAll" method="post"><input v-for="(value, name) in csrfFields()" :key="name" type="hidden" :name="name" :value="value"><button class="rounded-lg border border-primary px-4 py-2 text-sm font-semibold text-primary hover:bg-primary-subtle">Marcar todas como leídas</button></form>
    </header>

    <div class="rounded-xl border border-border bg-surface-raised shadow-card">
      <div v-if="notificationPage.items.length" class="divide-y divide-border-subtle">
        <article v-for="item in notificationPage.items" :key="item.id" class="flex gap-3 p-4 sm:p-5" :class="!item.readAt && 'bg-primary-subtle/40'">
          <BellIcon class="mt-0.5 size-5 shrink-0 text-primary" aria-hidden="true" />
          <div class="min-w-0 flex-1"><div class="flex flex-wrap items-center gap-2"><h2 class="font-semibold text-ink">{{ item.title }}</h2><span class="rounded-full bg-surface-muted px-2 py-0.5 text-xs text-ink-muted">{{ item.severity }}</span></div><p class="mt-1 text-sm text-ink-muted">{{ item.summary }}</p><div class="mt-3 flex flex-wrap gap-3 text-sm"><a v-if="item.url" :href="item.url" class="font-semibold text-primary hover:underline">Abrir detalle</a><form v-if="!item.readAt" :action="`${data.urls.read}/${item.id}`" method="post"><input v-for="(value, name) in csrfFields()" :key="name" type="hidden" :name="name" :value="value"><button class="font-semibold text-ink-muted hover:text-primary">Marcar leída</button></form></div></div>
        </article>
      </div>
      <div v-else class="p-10 text-center"><CheckCircleIcon class="mx-auto size-10 text-success"/><p class="mt-3 font-semibold text-ink">No hay notificaciones recientes</p></div>
      <PaginationBar :pagination="notificationPage.pagination ?? { page: 1, perPage: 10, total: 0, totalPages: 1 }" />
    </div>

    <section class="rounded-xl border border-border bg-surface-raised p-4 shadow-card sm:p-6">
      <div class="flex items-start gap-3"><DevicePhoneMobileIcon class="size-6 text-primary"/><div><h2 class="text-lg font-bold text-ink">Notificaciones en este dispositivo</h2><p class="text-sm text-ink-muted">El permiso se solicita únicamente cuando elegís activarlas.</p></div></div>
      <p class="mt-3 text-sm font-semibold text-ink" role="status">{{ pushStatus }}</p>
      <div class="mt-4 flex flex-wrap gap-3"><button type="button" class="rounded-lg bg-primary px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50" :disabled="busy || !pushConfigured || !!currentSubscription" @click="activatePush">Activar notificaciones en este dispositivo</button><button type="button" class="rounded-lg border border-primary px-4 py-2.5 text-sm font-semibold text-primary disabled:opacity-50" :disabled="busy || !currentSubscription" @click="sendTestPush">Enviar push de prueba</button><button type="button" class="rounded-lg border border-danger px-4 py-2.5 text-sm font-semibold text-danger disabled:opacity-50" :disabled="busy || !currentSubscription" @click="deactivatePush">Desactivar este dispositivo</button></div>
      <p v-if="pushMessage" class="mt-3 text-sm text-ink-muted" role="status">{{ pushMessage }}</p>
    </section>

    <section class="rounded-xl border border-border bg-surface-raised p-4 shadow-card sm:p-6">
      <h2 class="text-lg font-bold text-ink">Preferencias por evento</h2><p class="mb-4 text-sm text-ink-muted">La notificación interna se conserva como historial aunque falle un canal externo.</p>
      <div class="space-y-4">
        <form v-for="event in eventTypes" :key="event[0]" :action="data.urls.preferences" method="post" class="grid gap-3 rounded-lg border border-border-subtle p-3 md:grid-cols-[1.4fr_1fr_repeat(2,1fr)_auto] md:items-end">
          <input v-for="(value, name) in csrfFields()" :key="name" type="hidden" :name="name" :value="value"><input type="hidden" name="event_type" :value="event[0]"><input type="hidden" name="internal" value="INMEDIATO"><p class="font-semibold text-ink">{{ event[1] }}</p><p class="text-xs text-ink-muted"><span class="font-semibold uppercase">Interna</span><br>Siempre activa</p>
          <label v-for="channel in ['email','push']" :key="channel" class="text-xs font-semibold uppercase tracking-wide text-ink-muted">{{ channel }}<select :name="channel" class="mt-1 w-full rounded-lg border-border text-sm"><option v-for="mode in modes" :key="mode[0]" :value="mode[0]" :selected="(data.preferences?.[event[0]]?.[channel] ?? (channel === 'email' ? 'RESUMEN' : 'CRITICO')) === mode[0]">{{ mode[1] }}</option></select></label>
          <button class="rounded-lg bg-primary px-3 py-2 text-sm font-semibold text-white">Guardar</button>
        </form>
      </div>
    </section>
  </section>
</template>
