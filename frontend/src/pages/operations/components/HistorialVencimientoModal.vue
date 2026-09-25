<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { ArrowDownTrayIcon, ClockIcon, DocumentTextIcon, ExclamationTriangleIcon, XMarkIcon } from '@heroicons/vue/24/outline'
import { primaryButton, secondaryButton } from '../helpers.js'

const props = defineProps({
  expirationId: { type: [Number, String], required: true },
  historyUrl: { type: String, required: true },
  csrf: { type: Object, default: null },
})

const emit = defineEmits(['close'])

const loading = ref(false)
const errorMessage = ref('')
const expiration = ref(null)
const history = ref([])

async function load() {
  loading.value = true
  errorMessage.value = ''
  try {
    const response = await fetch(props.historyUrl, {
      headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin',
    })
    if (! response.ok) {
      const payload = await response.json().catch(() => ({}))
      throw new Error(payload.error || 'No se pudo cargar el historial del vencimiento.')
    }
    const data = await response.json()
    if (! data.ok) {
      throw new Error(data.error || 'No se pudo cargar el historial del vencimiento.')
    }
    expiration.value = data.expiration
    history.value = data.history || []
  } catch (exception) {
    errorMessage.value = exception.message || 'Error desconocido al cargar el historial.'
  } finally {
    loading.value = false
  }
}

onMounted(load)
watch(() => props.expirationId, load)

const sortedHistory = computed(() => history.value.slice())

function formatDate(value) {
  if (! value) return ''
  return String(value).slice(0, 10)
}

function formatDateTime(value) {
  if (! value) return ''
  return String(value).slice(0, 16).replace('T', ' ')
}

function formatBytes(value) {
  const bytes = Number(value) || 0
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${Math.ceil(bytes / 1024)} KB`
  return `${(bytes / 1024 / 1024).toFixed(1)} MB`
}

function userLabel(entry) {
  if (! entry.usuario_nombre) return entry.usuario_id ? `Usuario #${entry.usuario_id}` : 'Sistema'
  return entry.usuario_nombre
}
</script>

<template>
  <Teleport to="body">
    <div
      class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/55 p-3 backdrop-blur-[1px] sm:p-5"
      data-testid="historial-vencimiento-modal"
      role="dialog"
      aria-modal="true"
      aria-labelledby="historial-vencimiento-title"
      @click.self="emit('close')"
      @keydown.esc="emit('close')"
    >
      <section class="flex max-h-[calc(100vh-1.5rem)] w-full max-w-2xl flex-col overflow-hidden rounded-2xl border border-border bg-surface-raised shadow-2xl sm:max-h-[calc(100vh-2.5rem)]">
        <header class="flex items-start justify-between gap-4 border-b border-border-subtle px-5 py-4 sm:px-6">
          <div>
            <p class="text-xs font-bold uppercase tracking-[0.14em] text-primary">Historial de renovaciones</p>
            <h2 id="historial-vencimiento-title" class="mt-1 text-xl font-bold text-ink">
              {{ expiration ? (expiration.tipo_nombre || 'Vencimiento') : 'Vencimiento' }}
            </h2>
            <p v-if="expiration" class="mt-1 text-sm text-ink-muted">
              Sujeto: {{ expiration.subject_name || 'Sin sujeto' }}.
            </p>
          </div>
          <button type="button" :class="secondaryButton" aria-label="Cerrar" @click="emit('close')">
            <XMarkIcon class="size-5" aria-hidden="true" />
          </button>
        </header>

        <div class="flex flex-col gap-4 overflow-y-auto px-5 py-4 sm:px-6">
          <div
            v-if="errorMessage"
            class="flex items-start gap-3 rounded-xl border border-danger/30 bg-danger/10 px-3 py-2 text-sm text-danger"
            role="alert"
            data-testid="historial-vencimiento-error"
          >
            <ExclamationTriangleIcon class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
            <span>{{ errorMessage }}</span>
          </div>

          <p v-if="loading && ! errorMessage" class="text-sm text-ink-muted">Cargando historial...</p>

          <ol v-if="sortedHistory.length > 0" class="flex flex-col gap-3">
            <li
              v-for="entry in sortedHistory"
              :key="entry.id"
              class="rounded-xl border border-border-subtle bg-surface px-4 py-3"
              data-testid="historial-vencimiento-entry"
            >
              <div class="flex items-center justify-between gap-3 text-sm font-semibold text-ink">
                <span class="inline-flex items-center gap-2">
                  <ClockIcon class="size-4 text-ink-muted" aria-hidden="true" />
                  {{ formatDate(entry.fecha_anterior) }} -> {{ formatDate(entry.fecha_nueva) }}
                </span>
                <span class="text-xs text-ink-muted">
                  {{ formatDateTime(entry.fecha_renovacion) }}
                </span>
              </div>
              <p class="mt-1 text-xs text-ink-muted">
                Renovado por <span class="font-semibold text-ink">{{ userLabel(entry) }}</span>.
              </p>
              <p v-if="entry.observaciones" class="mt-2 whitespace-pre-line rounded-lg bg-surface-raised px-3 py-2 text-sm text-ink">
                {{ entry.observaciones }}
              </p>
              <div v-if="entry.evidencia" class="mt-2 flex items-center gap-2 text-xs">
                <DocumentTextIcon class="size-4 text-ink-muted" aria-hidden="true" />
                <span class="text-ink-muted">{{ entry.evidencia.nombre_original }}</span>
                <span class="text-ink-subtle">({{ formatBytes(entry.evidencia.tamanio) }})</span>
                <a
                  :href="entry.evidencia.download_url"
                  class="inline-flex items-center gap-1 rounded-md border border-border-strong bg-surface-raised px-2 py-1 text-xs font-semibold text-ink hover:bg-surface-muted"
                  data-testid="historial-vencimiento-evidencia-link"
                >
                  <ArrowDownTrayIcon class="size-3" aria-hidden="true" />
                  Ver evidencia
                </a>
              </div>
            </li>
          </ol>

          <p
            v-else-if="! loading && ! errorMessage"
            class="rounded-xl border border-dashed border-border-subtle bg-surface px-4 py-6 text-center text-sm text-ink-muted"
          >
            Todavia no se registran renovaciones para este vencimiento.
          </p>
        </div>

        <footer class="flex flex-col-reverse items-stretch gap-2 border-t border-border-subtle bg-surface px-5 py-3 sm:flex-row sm:items-center sm:justify-end sm:px-6">
          <button type="button" :class="primaryButton" @click="emit('close')">
            Cerrar
          </button>
        </footer>
      </section>
    </div>
  </Teleport>
</template>
