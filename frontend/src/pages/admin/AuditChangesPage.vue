<script setup>
import { computed, onMounted, reactive, ref } from 'vue'

const props = defineProps({ data: { type: Object, default: () => ({}) } })

const filters = reactive({
  companyId: '',
  branchId: '',
  actorUserId: '',
  action: '',
  entity: '',
  dateFrom: '',
  dateTo: '',
  q: '',
})

const loading = ref(false)
const error = ref('')
const items = ref([])
const page = ref(1)
const pages = ref(0)
const total = ref(0)
const selected = ref(null)

const apiUrl = computed(() => {
  if (props.data.apiUrl) return props.data.apiUrl.replace(/\/$/, '')
  const current = window.location.pathname
  const root = current.includes('/superadmin') ? current.split('/superadmin')[0] : ''
  return `${root}/superadmin/auditoria`.replace(/\/+/g, '/')
})

const ACTION_LABELS = {
  'empresa.creada': 'Empresa creada',
  'empresa.actualizada': 'Empresa actualizada',
  'sucursal.creada': 'Sucursal creada',
  'sucursal.actualizada': 'Sucursal actualizada',
  'usuario.creado': 'Usuario creado',
  'usuario.actualizado': 'Usuario actualizado',
  'usuario.empresa_cambiada': 'Usuario cambiado de empresa',
  'usuario.roles_asignados': 'Roles asignados',
  'usuario.acceso_asignado': 'Acceso asignado',
  'usuario.password_restablecida': 'Contraseña restablecida',
}

const SCOPE_LABELS = { GLOBAL: 'Global', EMPRESA: 'Empresa', SUCURSAL: 'Sucursal' }

function qs(targetPage = 1) {
  const params = new URLSearchParams({ page: String(targetPage), perPage: '25' })
  for (const [key, value] of Object.entries(filters)) {
    if (value !== '' && value !== null) params.set(key, value)
  }
  return params
}

async function load(targetPage = 1) {
  loading.value = true
  error.value = ''
  try {
    const response = await fetch(`${apiUrl.value}?${qs(targetPage)}`, { headers: { Accept: 'application/json' } })
    if (!response.ok) {
      const payload = await response.json().catch(() => ({}))
      throw new Error(payload.error || 'No se pudo cargar la auditoría de cambios.')
    }
    const payload = await response.json()
    items.value = Array.isArray(payload.data) ? payload.data : []
    page.value = payload.pagination?.page ?? targetPage
    pages.value = payload.pagination?.pages ?? 0
    total.value = payload.pagination?.total ?? 0
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

function reset() {
  Object.assign(filters, {
    companyId: '',
    branchId: '',
    actorUserId: '',
    action: '',
    entity: '',
    dateFrom: '',
    dateTo: '',
    q: '',
  })
  selected.value = null
  load(1)
}

function actionLabel(value) {
  return ACTION_LABELS[value] ?? value
}

function scopeLabel(value) {
  return SCOPE_LABELS[value] ?? value
}

function formatDate(value) {
  if (!value) return '—'
  const normalized = value.includes('T') ? value : value.replace(' ', 'T')
  const date = new Date(normalized)
  if (Number.isNaN(date.getTime())) return value
  return new Intl.DateTimeFormat('es-AR', { dateStyle: 'short', timeStyle: 'short' }).format(date)
}

function pretty(value) {
  return value === null || value === undefined ? '—' : JSON.stringify(value, null, 2)
}

onMounted(() => load(1))
</script>

<template>
  <section class="space-y-5">
    <header>
      <h1 class="text-2xl font-bold text-ink">{{ data.title || 'Auditoría de cambios' }}</h1>
      <p class="mt-1 text-sm text-ink-muted">{{ data.subtitle || 'Operaciones sensibles registradas en todas las empresas.' }}</p>
    </header>

    <form class="grid gap-3 rounded-xl border border-border bg-surface-raised p-4 md:grid-cols-2 xl:grid-cols-4" @submit.prevent="load(1)">
      <input v-model="filters.companyId" class="rounded-lg border border-border-strong bg-surface px-3 py-2 text-ink placeholder:text-ink-subtle focus:border-border-focus focus:outline-none focus:ring-2 focus:ring-primary/20" inputmode="numeric" placeholder="ID empresa" aria-label="ID de empresa">
      <input v-model="filters.branchId" class="rounded-lg border border-border-strong bg-surface px-3 py-2 text-ink placeholder:text-ink-subtle focus:border-border-focus focus:outline-none focus:ring-2 focus:ring-primary/20" inputmode="numeric" placeholder="ID sucursal" aria-label="ID de sucursal">
      <input v-model="filters.actorUserId" class="rounded-lg border border-border-strong bg-surface px-3 py-2 text-ink placeholder:text-ink-subtle focus:border-border-focus focus:outline-none focus:ring-2 focus:ring-primary/20" inputmode="numeric" placeholder="ID usuario actor" aria-label="ID de usuario actor">
      <input v-model="filters.action" class="rounded-lg border border-border-strong bg-surface px-3 py-2 text-ink placeholder:text-ink-subtle focus:border-border-focus focus:outline-none focus:ring-2 focus:ring-primary/20" placeholder="Acción (ej.: usuario.creado)" aria-label="Acción">
      <input v-model="filters.entity" class="rounded-lg border border-border-strong bg-surface px-3 py-2 text-ink placeholder:text-ink-subtle focus:border-border-focus focus:outline-none focus:ring-2 focus:ring-primary/20" placeholder="Entidad (ej.: usuarios)" aria-label="Entidad">
      <input v-model="filters.dateFrom" class="rounded-lg border border-border-strong bg-surface px-3 py-2 text-ink focus:border-border-focus focus:outline-none focus:ring-2 focus:ring-primary/20" type="date" aria-label="Fecha desde">
      <input v-model="filters.dateTo" class="rounded-lg border border-border-strong bg-surface px-3 py-2 text-ink focus:border-border-focus focus:outline-none focus:ring-2 focus:ring-primary/20" type="date" aria-label="Fecha hasta">
      <input v-model="filters.q" class="rounded-lg border border-border-strong bg-surface px-3 py-2 text-ink placeholder:text-ink-subtle focus:border-border-focus focus:outline-none focus:ring-2 focus:ring-primary/20" placeholder="Texto, entidad o usuario" aria-label="Texto, entidad o usuario">
      <div class="flex gap-2 md:col-span-2 xl:col-span-4">
        <button class="ui-interactive rounded-lg bg-primary px-4 py-2 font-semibold text-primary-foreground hover:bg-primary-hover" type="submit">Filtrar</button>
        <button class="ui-interactive rounded-lg border border-border-strong bg-surface px-4 py-2 font-semibold text-ink hover:bg-surface-muted" type="button" @click="reset">Limpiar</button>
      </div>
    </form>

    <p v-if="error" class="rounded-lg border border-danger/35 bg-danger-subtle p-3 text-sm font-medium text-danger-strong" role="alert" aria-live="assertive">{{ error }}</p>
    <p v-if="loading" class="text-sm text-ink-muted" role="status" aria-live="polite">Cargando cambios…</p>
    <div v-else-if="!items.length" class="rounded-xl border border-dashed border-border-strong p-8 text-center text-ink-muted">No hay cambios registrados para estos filtros.</div>

    <div v-else class="overflow-hidden rounded-xl border border-border bg-surface-raised">
      <div class="hidden grid-cols-12 gap-3 border-b border-border-subtle bg-surface-subtle px-4 py-2 text-xs font-semibold uppercase text-ink-muted md:grid">
        <span class="col-span-2">Fecha</span>
        <span class="col-span-3">Empresa / sucursal</span>
        <span class="col-span-2">Actor</span>
        <span class="col-span-3">Acción</span>
        <span class="col-span-2">Entidad</span>
      </div>
      <button
        v-for="item in items"
        :key="item.id"
        type="button"
        class="ui-interactive grid w-full gap-2 border-b border-border-subtle px-4 py-4 text-left text-ink last:border-b-0 hover:bg-surface-muted focus-visible:relative focus-visible:z-10 md:grid-cols-12 md:gap-3"
        @click="selected = item"
      >
        <span class="text-sm text-ink-muted md:col-span-2">{{ formatDate(item.fecha) }}</span>
        <span class="md:col-span-3">
          <strong class="block">{{ item.company?.name || 'Global' }}</strong>
          <small class="text-ink-muted">{{ item.branch?.name || scopeLabel(item.scope) }}</small>
        </span>
        <span class="md:col-span-2">
          <strong class="block">{{ item.actor?.name || 'Proceso automático' }}</strong>
          <small class="text-ink-muted">{{ item.actor?.email || '' }}</small>
        </span>
        <span class="md:col-span-3">
          <strong class="block">{{ actionLabel(item.action) }}</strong>
          <small class="text-ink-muted">{{ item.action }}</small>
        </span>
        <span class="md:col-span-2">
          {{ item.entity }}<span v-if="item.entityId"> #{{ item.entityId }}</span>
        </span>
      </button>
    </div>

    <footer v-if="pages > 1" class="flex items-center justify-between text-sm">
      <span class="text-ink-muted">{{ total }} cambios</span>
      <div class="flex gap-2">
        <button type="button" class="rounded border border-border-strong bg-surface px-3 py-1 text-ink hover:bg-surface-muted disabled:cursor-not-allowed disabled:opacity-50" :disabled="page <= 1" @click="load(page - 1)">Anterior</button>
        <span class="px-2 py-1 text-ink-muted">{{ page }} / {{ pages }}</span>
        <button type="button" class="rounded border border-border-strong bg-surface px-3 py-1 text-ink hover:bg-surface-muted disabled:cursor-not-allowed disabled:opacity-50" :disabled="page >= pages" @click="load(page + 1)">Siguiente</button>
      </div>
    </footer>

    <div v-if="selected" class="rounded-xl border border-border bg-surface-raised p-5">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h2 class="font-semibold text-ink">Cambio #{{ selected.id }}</h2>
          <p class="mt-1 text-sm text-ink-muted">{{ actionLabel(selected.action) }} · {{ formatDate(selected.fecha) }}</p>
        </div>
        <button type="button" class="rounded-lg border border-border-strong px-3 py-1 text-sm font-semibold text-ink hover:bg-surface-muted" @click="selected = null">Cerrar</button>
      </div>

      <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2 xl:grid-cols-4">
        <div><dt class="text-ink-muted">Empresa afectada</dt><dd class="font-semibold text-ink">{{ selected.company?.name || 'Global' }}</dd></div>
        <div><dt class="text-ink-muted">Sucursal afectada</dt><dd class="font-semibold text-ink">{{ selected.branch?.name || '—' }}</dd></div>
        <div><dt class="text-ink-muted">Actor</dt><dd class="font-semibold text-ink">{{ selected.actor?.name || 'Proceso automático' }}</dd></div>
        <div><dt class="text-ink-muted">Alcance</dt><dd class="font-semibold text-ink">{{ scopeLabel(selected.scope) }}</dd></div>
        <div><dt class="text-ink-muted">Entidad</dt><dd class="font-semibold text-ink">{{ selected.entity }}<span v-if="selected.entityId"> #{{ selected.entityId }}</span></dd></div>
        <div><dt class="text-ink-muted">Motivo</dt><dd class="font-semibold text-ink">{{ selected.reason || '—' }}</dd></div>
        <div><dt class="text-ink-muted">IP</dt><dd class="font-semibold text-ink">{{ selected.ip || '—' }}</dd></div>
        <div><dt class="text-ink-muted">Request ID</dt><dd class="font-semibold text-ink">{{ selected.requestId || '—' }}</dd></div>
      </dl>

      <div class="mt-5 grid gap-4 lg:grid-cols-2">
        <section>
          <h3 class="text-sm font-semibold uppercase text-ink-muted">Valores anteriores</h3>
          <pre class="mt-2 max-h-80 overflow-auto rounded-lg border border-border bg-surface-subtle p-3 text-xs text-ink">{{ pretty(selected.before) }}</pre>
        </section>
        <section>
          <h3 class="text-sm font-semibold uppercase text-ink-muted">Valores nuevos</h3>
          <pre class="mt-2 max-h-80 overflow-auto rounded-lg border border-border bg-surface-subtle p-3 text-xs text-ink">{{ pretty(selected.after) }}</pre>
        </section>
      </div>
    </div>
  </section>
</template>
