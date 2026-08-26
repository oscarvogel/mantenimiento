<script setup>
import { BellIcon } from '@heroicons/vue/24/outline'
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'

const props = defineProps({
  enabled: { type: Boolean, default: false },
  summaryUrl: { type: String, required: true },
  centerUrl: { type: String, required: true },
})

const root = ref(null)
const open = ref(false)
const loading = ref(false)
const error = ref(false)
const unread = ref(0)
const items = ref([])

const label = computed(() => unread.value ? `${unread.value} notificaciones sin leer` : 'Notificaciones')

const formatDate = (value) => {
  if (!value) return ''
  const parsed = new Date(String(value).replace(' ', 'T'))
  if (Number.isNaN(parsed.getTime())) return value
  return new Intl.DateTimeFormat('es-AR', { dateStyle: 'short', timeStyle: 'short' }).format(parsed)
}

const severityClass = (severity) => {
  const value = String(severity || '').toUpperCase()
  if (['CRITICA', 'CRITICAL', 'ERROR'].includes(value)) return 'bg-danger'
  if (['ADVERTENCIA', 'WARNING'].includes(value)) return 'bg-warning'
  return 'bg-primary'
}

const loadSummary = async () => {
  if (!props.enabled || loading.value) return
  loading.value = true
  error.value = false
  try {
    const response = await fetch(props.summaryUrl, {
      headers: { Accept: 'application/json' },
      credentials: 'same-origin',
    })
    if (!response.ok) throw new Error(`HTTP ${response.status}`)
    const payload = await response.json()
    unread.value = Number(payload.unread) || 0
    items.value = Array.isArray(payload.items) ? payload.items : []
  } catch (_) {
    error.value = true
  } finally {
    loading.value = false
  }
}

const toggle = async () => {
  open.value = !open.value
  if (open.value && !error.value) await loadSummary()
}

const closeOnOutside = (event) => {
  if (open.value && root.value && !root.value.contains(event.target)) open.value = false
}

const closeOnEscape = (event) => {
  if (event.key === 'Escape') open.value = false
}

onMounted(() => {
  if (props.enabled) loadSummary()
  document.addEventListener('click', closeOnOutside)
  document.addEventListener('keydown', closeOnEscape)
})

onBeforeUnmount(() => {
  document.removeEventListener('click', closeOnOutside)
  document.removeEventListener('keydown', closeOnEscape)
})
</script>

<template>
  <div v-if="enabled" ref="root" class="relative">
    <button
      type="button"
      class="relative rounded-lg p-2 text-ink-muted hover:bg-surface-muted hover:text-ink focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
      :aria-label="label"
      :aria-expanded="open"
      aria-haspopup="dialog"
      @click.stop="toggle"
    >
      <BellIcon class="size-6" aria-hidden="true" />
      <span v-if="unread" class="absolute right-0 top-0 min-w-4 rounded-full bg-danger px-1 text-center text-[10px] font-bold leading-4 text-white">
        {{ unread > 99 ? '99+' : unread }}
      </span>
    </button>

    <div
      v-if="open"
      class="fixed inset-x-3 top-[4.25rem] z-50 overflow-hidden rounded-xl border border-border bg-surface-raised shadow-xl sm:absolute sm:inset-x-auto sm:right-0 sm:top-11 sm:w-96"
      role="dialog"
      aria-label="Resumen de notificaciones"
    >
      <div class="flex items-center justify-between border-b border-border px-4 py-3">
        <div>
          <p class="text-sm font-semibold text-ink">Notificaciones</p>
          <p class="text-xs text-ink-muted">{{ unread ? `${unread} sin leer` : 'Todo al día' }}</p>
        </div>
        <a :href="centerUrl" class="text-xs font-semibold text-primary hover:underline">Ver todas</a>
      </div>

      <div v-if="loading && !items.length" class="px-4 py-8 text-center text-sm text-ink-muted">Cargando notificaciones…</div>
      <div v-else-if="error && !items.length" class="px-4 py-6 text-center">
        <p class="text-sm font-medium text-ink">No se pudo cargar el resumen.</p>
        <button type="button" class="mt-2 text-xs font-semibold text-primary hover:underline" @click="loadSummary">Reintentar</button>
      </div>
      <div v-else-if="!items.length" class="px-4 py-8 text-center">
        <p class="text-sm font-medium text-ink">No tenés notificaciones pendientes.</p>
        <p class="mt-1 text-xs text-ink-muted">Los avisos importantes van a aparecer acá.</p>
      </div>
      <div v-else class="max-h-[26rem] divide-y divide-border overflow-y-auto">
        <a
          v-for="item in items"
          :key="item.id"
          :href="item.url || centerUrl"
          class="flex gap-3 px-4 py-3 hover:bg-surface-muted"
        >
          <span class="mt-1.5 size-2.5 shrink-0 rounded-full" :class="severityClass(item.severity)" aria-hidden="true" />
          <span class="min-w-0 flex-1">
            <span class="flex items-start justify-between gap-3">
              <span class="text-sm font-semibold text-ink">{{ item.title }}</span>
              <span v-if="!item.readAt" class="mt-1 size-2 shrink-0 rounded-full bg-primary" title="Sin leer" />
            </span>
            <span class="mt-0.5 block text-xs leading-5 text-ink-muted">{{ item.summary }}</span>
            <span class="mt-1 block text-[11px] text-ink-subtle">{{ formatDate(item.createdAt) }}</span>
          </span>
        </a>
      </div>
    </div>
  </div>
</template>
