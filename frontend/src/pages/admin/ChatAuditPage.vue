<script setup>
import { computed, onMounted, reactive, ref } from 'vue'

const props = defineProps({ data: { type: Object, default: () => ({}) } })
const filters = reactive({ companyId: '', userId: '', dateFrom: '', dateTo: '', q: '' })
const loading = ref(false)
const error = ref('')
const items = ref([])
const page = ref(1)
const pages = ref(0)
const total = ref(0)
const selected = ref(null)
const detailLoading = ref(false)
const showCompanyFilter = computed(() => props.data.showCompanyFilter !== false)
const apiUrl = computed(() => {
  if (props.data.apiUrl) return props.data.apiUrl.replace(/\/$/, '')
  const current = window.location.pathname
  const detectedRoot = current.includes('/superadmin') ? current.split('/superadmin')[0] : current.split('/administracion')[0]
  const root = detectedRoot && detectedRoot !== '/' ? detectedRoot.replace(/\/+$/, '') : '/mantenimiento'
  return `${root}/chatbot/auditoria`.replace(/\/+/g, '/')
})

function qs(targetPage = 1) {
  const p = new URLSearchParams({ page: String(targetPage), perPage: '25' })
  for (const [key, value] of Object.entries(filters)) if (value) p.set(key, value)
  return p
}

async function load(targetPage = 1) {
  loading.value = true; error.value = ''
  try {
    const response = await fetch(`${apiUrl.value}?${qs(targetPage)}`, { headers: { Accept: 'application/json' } })
    if (!response.ok) throw new Error(response.status === 403 ? 'No tenés permiso para ver esta auditoría.' : 'No se pudo cargar la auditoría.')
    const payload = await response.json()
    const itemsData = payload.data ?? payload.items ?? []
    items.value = Array.isArray(itemsData) ? itemsData : []
    page.value = payload.pagination?.page ?? targetPage
    pages.value = payload.pagination?.pages ?? 0
    total.value = payload.pagination?.total ?? 0
  } catch (e) { error.value = e.message } finally { loading.value = false }
}

async function openDetail(id) {
  detailLoading.value = true; error.value = ''
  try {
    const response = await fetch(`${apiUrl.value}/${id}`, { headers: { Accept: 'application/json' } })
    const payload = await response.json()
    selected.value = payload.data ?? payload.conversation ?? null
  } catch (e) { error.value = e.message } finally { detailLoading.value = false }
}

function reset() {
  Object.assign(filters, { companyId: '', userId: '', dateFrom: '', dateTo: '', q: '' })
  load(1)
}

function formatDate(value) { return value ? new Intl.DateTimeFormat('es-AR', { dateStyle: 'short', timeStyle: 'short' }).format(new Date(value)) : '—' }
function pretty(value) { return typeof value === 'string' ? value : JSON.stringify(value, null, 2) }
onMounted(() => load(1))
</script>

<template>
  <section class="space-y-5">
    <header>
      <h1 class="text-2xl font-bold text-ink">{{ data.title || 'Auditoría del chatbot' }}</h1>
      <p class="mt-1 text-sm text-ink-muted">{{ data.subtitle }}</p>
    </header>

    <form class="grid gap-3 rounded-xl border border-border bg-surface-raised p-4" @submit.prevent="load(1)">
      <input v-if="showCompanyFilter" v-model="filters.companyId" class="rounded-lg border border-border-strong bg-surface px-3 py-2 text-ink placeholder:text-ink-subtle focus:border-border-focus focus:outline-none focus:ring-2 focus:ring-primary/20" inputmode="numeric" placeholder="ID empresa" aria-label="ID de empresa">
      <input v-model="filters.userId" class="rounded-lg border border-border-strong bg-surface px-3 py-2 text-ink placeholder:text-ink-subtle focus:border-border-focus focus:outline-none focus:ring-2 focus:ring-primary/20" inputmode="numeric" placeholder="ID usuario" aria-label="ID de usuario">
      <input v-model="filters.dateFrom" class="rounded-lg border border-border-strong bg-surface px-3 py-2 text-ink focus:border-border-focus focus:outline-none focus:ring-2 focus:ring-primary/20" type="date" aria-label="Fecha desde">
      <input v-model="filters.dateTo" class="rounded-lg border border-border-strong bg-surface px-3 py-2 text-ink focus:border-border-focus focus:outline-none focus:ring-2 focus:ring-primary/20" type="date" aria-label="Fecha hasta">
      <input v-model="filters.q" class="rounded-lg border border-border-strong bg-surface px-3 py-2 text-ink placeholder:text-ink-subtle focus:border-border-focus focus:outline-none focus:ring-2 focus:ring-primary/20" placeholder="Texto o ID conversación" aria-label="Texto o ID de conversación">
      <div class="flex gap-2 md:col-span-2 xl:col-span-5">
        <button class="ui-interactive rounded-lg bg-primary px-4 py-2 font-semibold text-primary-foreground hover:bg-primary-hover" type="submit">Filtrar</button>
        <button class="ui-interactive rounded-lg border border-border-strong bg-surface px-4 py-2 font-semibold text-ink hover:bg-surface-muted" type="button" @click="reset">Limpiar</button>
      </div>
    </form>

    <p v-if="error" class="rounded-lg border border-danger/35 bg-danger-subtle p-3 text-sm font-medium text-danger-strong" role="alert" aria-live="assertive">{{ error }}</p>
    <p v-if="loading" class="text-sm text-ink-muted" role="status" aria-live="polite">Cargando conversaciones…</p>
    <div v-else-if="!items.length" class="rounded-xl border border-dashed border-border-strong p-8 text-center text-ink-muted">No hay conversaciones para estos filtros.</div>

    <div v-else class="overflow-hidden rounded-xl border border-border bg-surface-raised">
      <div class="hidden grid-cols-12 gap-3 border-b border-border-subtle bg-surface-subtle px-4 py-2 text-xs font-semibold uppercase text-ink-muted md:grid">
        <span class="col-span-3">Empresa / usuario</span><span class="col-span-4">Conversación</span><span class="col-span-2">Mensajes</span><span class="col-span-3">Última actividad</span>
      </div>
      <button v-for="item in items" :key="item.id" type="button" class="ui-interactive grid w-full gap-2 border-b border-border-subtle px-4 py-4 text-left text-ink last:border-b-0 hover:bg-surface-muted focus-visible:relative focus-visible:z-10 md:grid-cols-12 md:gap-3" @click="openDetail(item.id)">
        <span class="md:col-span-3"><strong class="block">{{ item.companyName || `Empresa ${item.companyId}` }}</strong><small class="text-ink-muted">{{ item.userName || item.userEmail || `Usuario ${item.userId}` }}</small></span>
        <span class="md:col-span-4"><strong>#{{ item.id }}</strong> · {{ item.title || 'Sin título' }}</span>
        <span class="md:col-span-2">{{ item.messageCount }}</span>
        <span class="md:col-span-3 text-sm text-ink-muted">{{ formatDate(item.updatedAt) }}</span>
      </button>
    </div>

    <footer v-if="pages > 1" class="flex items-center justify-between text-sm">
      <span class="text-ink-muted">{{ total }} conversaciones</span>
      <div class="flex gap-2"><button type="button" class="rounded border border-border-strong bg-surface px-3 py-1 text-ink hover:bg-surface-muted disabled:cursor-not-allowed disabled:opacity-50" :disabled="page <= 1" @click="load(page - 1)">Anterior</button><span class="px-2 py-1 text-ink-muted">{{ page }} / {{ pages }}</span><button type="button" class="rounded border border-border-strong bg-surface px-3 py-1 text-ink hover:bg-surface-muted disabled:cursor-not-allowed disabled:opacity-50" :disabled="page >= pages" @click="load(page + 1)">Siguiente</button></div>
    </footer>

    <div v-if="selected || detailLoading" class="fixed inset-0 z-50 flex justify-end bg-brand-950/60" role="presentation" @click.self="selected = null">
      <aside class="h-full w-full overflow-y-auto bg-surface-raised p-5 text-ink shadow-2xl md:max-w-3xl" role="dialog" aria-modal="true" aria-labelledby="chat-audit-detail-title">
        <button type="button" class="ui-interactive float-right rounded-lg border border-border-strong bg-surface px-3 py-1 text-sm font-semibold text-ink hover:bg-surface-muted" @click="selected = null">Cerrar</button>
        <p v-if="detailLoading" id="chat-audit-detail-title" class="text-ink-muted" role="status" aria-live="polite">Cargando detalle…</p>
        <template v-else-if="selected">
          <h2 id="chat-audit-detail-title" class="pr-20 text-xl font-bold text-ink">#{{ selected.id }} · {{ selected.title || 'Sin título' }}</h2>
          <p class="mt-1 text-sm text-ink-muted">{{ selected.companyName }} · {{ selected.userName || selected.userEmail }} · {{ selected.messageCount }} mensajes</p>
          <ol class="mt-5 space-y-4">
            <li v-for="message in selected.messages" :key="message.id" class="rounded-xl border border-border-subtle bg-surface p-4">
              <div class="mb-2 flex flex-wrap justify-between gap-2 text-xs font-semibold uppercase text-ink-muted"><span>{{ message.role }}</span><span>{{ formatDate(message.createdAt) }} · tokens {{ message.tokensUsed ?? '—' }}</span></div>
              <p class="whitespace-pre-wrap text-sm text-ink">{{ message.content }}</p>
              <details v-if="message.toolCalls" class="mt-3 rounded-lg bg-surface-subtle p-3"><summary class="cursor-pointer font-semibold text-ink">Llamadas a herramientas</summary><pre class="mt-2 overflow-x-auto whitespace-pre-wrap text-xs text-ink-muted">{{ pretty(message.toolCalls) }}</pre></details>
            </li>
          </ol>
        </template>
      </aside>
    </div>
  </section>
</template>
