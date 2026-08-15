<script setup>
import { nextTick, ref, watch } from 'vue'
import { XMarkIcon } from '@heroicons/vue/24/outline'
import CsrfInput from './CsrfInput.vue'
import FormField from './FormField.vue'
import { fieldClass, primaryButton, secondaryButton } from '../helpers.js'

const props = defineProps({
  open: { type: Boolean, required: true },
  item: { type: Object, default: null },
  csrf: { type: Object, required: true },
  canEdit: { type: Boolean, default: false },
})

const emit = defineEmits(['close', 'saved'])
const form = ref(null)
const localItem = ref(null)
const errorMessage = ref('')
const submitting = ref(false)

const valueOrBlank = (value) => (value === null || value === undefined ? '' : value)

watch(
  () => props.open,
  (next) => {
    if (!next) return
    errorMessage.value = ''
    submitting.value = false
    if (!props.item) {
      localItem.value = null
      return
    }
    localItem.value = {
      id: props.item.id,
      serviceName: props.item.serviceName ?? '',
      intervalKm: valueOrBlank(props.item.intervalKm),
      intervalHours: valueOrBlank(props.item.intervalHours),
      intervalDays: valueOrBlank(props.item.intervalDays),
      warningKm: valueOrBlank(props.item.warningKm),
      warningHours: valueOrBlank(props.item.warningHours),
      warningDays: valueOrBlank(props.item.warningDays),
      priority: props.item.priority ?? 'MEDIA',
      active: !!props.item.active,
      notes: props.item.notes ?? '',
    }
    nextTick(() => form.value?.querySelector('input:not([type="hidden"]), select, textarea')?.focus())
  },
)

function close() {
  if (submitting.value) return
  emit('close')
}

function onBackdrop(event) {
  if (event.target === event.currentTarget) close()
}

function onKeydown(event) {
  if (event.key === 'Escape') {
    event.preventDefault()
    close()
  }
}

function numericValue(value) {
  if (value === '' || value === null || value === undefined) return null
  const parsed = Number(value)
  return Number.isFinite(parsed) ? parsed : null
}

function validate() {
  const intervals = [
    numericValue(localItem.value.intervalKm),
    numericValue(localItem.value.intervalHours),
    numericValue(localItem.value.intervalDays),
  ]
  if (!intervals.some((value) => value !== null && value > 0)) {
    return 'Indicá al menos un intervalo válido en km, horas o días.'
  }
  if (intervals.some((value) => value !== null && value <= 0)) {
    return 'Los intervalos informados deben ser mayores a cero.'
  }
  const warnings = [
    numericValue(localItem.value.warningKm),
    numericValue(localItem.value.warningHours),
    numericValue(localItem.value.warningDays),
  ]
  if (warnings.some((value) => value !== null && value < 0)) {
    return 'Las anticipaciones no pueden ser negativas.'
  }
  return ''
}

async function safeReadError(response) {
  try {
    const contentType = response.headers.get('content-type') || ''
    if (contentType.includes('application/json')) {
      const payload = await response.json()
      return payload?.error || payload?.message || ''
    }
    const text = await response.text()
    return text.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 240)
  } catch {
    return ''
  }
}

async function onSubmit(event) {
  event.preventDefault()
  if (!props.canEdit || !props.item || !form.value) return
  if (!props.item.updateUrl) {
    errorMessage.value = 'No está disponible la URL de edición de esta frecuencia.'
    return
  }

  const validationError = validate()
  if (validationError) {
    errorMessage.value = validationError
    return
  }

  errorMessage.value = ''
  submitting.value = true
  try {
    const response = await fetch(props.item.updateUrl, {
      method: 'POST',
      body: new FormData(form.value),
      credentials: 'same-origin',
      redirect: 'follow',
    })
    if (response.redirected) {
      window.location.assign(response.url)
      return
    }
    if (!response.ok) {
      errorMessage.value = (await safeReadError(response)) || 'No se pudo guardar la frecuencia.'
      submitting.value = false
      return
    }
    emit('saved', { item: localItem.value })
    submitting.value = false
  } catch (error) {
    errorMessage.value = error?.message || 'Error de red al guardar la frecuencia.'
    submitting.value = false
  }
}
</script>

<template>
  <Teleport to="body">
    <div
      v-if="open && localItem"
      class="fixed inset-0 z-50 flex items-end justify-center bg-ink/50 px-4 py-6 sm:items-center"
      role="dialog"
      aria-modal="true"
      :aria-labelledby="'frequency-modal-title-' + localItem.id"
      tabindex="-1"
      @click="onBackdrop"
      @keydown="onKeydown"
    >
      <div class="flex w-full max-w-2xl flex-col overflow-hidden rounded-2xl bg-white shadow-2xl">
        <header class="flex items-start justify-between gap-3 border-b border-border-subtle px-5 py-4">
          <div class="min-w-0">
            <p class="text-xs font-semibold uppercase tracking-wide text-ink-muted">Frecuencia preventiva</p>
            <h2 :id="'frequency-modal-title-' + localItem.id" class="truncate text-base font-bold text-ink">
              {{ localItem.serviceName || 'Editar frecuencia' }}
            </h2>
          </div>
          <button type="button" :class="secondaryButton" class="!min-h-9 !px-2" :disabled="submitting" aria-label="Cerrar" @click="close">
            <XMarkIcon class="size-4" aria-hidden="true" />
          </button>
        </header>

        <form ref="form" class="flex max-h-[75vh] flex-col" @submit="onSubmit">
          <CsrfInput :csrf="csrf" />
          <div class="grid flex-1 gap-3 overflow-y-auto px-5 py-4 md:grid-cols-3">
            <FormField label="Cada (km)" :for-id="'frequency-' + localItem.id + '-km'">
              <input :id="'frequency-' + localItem.id + '-km'" v-model="localItem.intervalKm" name="intervalo_km" type="number" min="1" step="1" :class="fieldClass" :disabled="!canEdit" />
            </FormField>
            <FormField label="Cada (horas)" :for-id="'frequency-' + localItem.id + '-hours'">
              <input :id="'frequency-' + localItem.id + '-hours'" v-model="localItem.intervalHours" name="intervalo_horas" type="number" min="0.01" step="0.01" :class="fieldClass" :disabled="!canEdit" />
            </FormField>
            <FormField label="Cada (días)" :for-id="'frequency-' + localItem.id + '-days'">
              <input :id="'frequency-' + localItem.id + '-days'" v-model="localItem.intervalDays" name="intervalo_dias" type="number" min="1" step="1" :class="fieldClass" :disabled="!canEdit" />
            </FormField>

            <FormField label="Avisar antes (km)" :for-id="'frequency-' + localItem.id + '-warning-km'">
              <input :id="'frequency-' + localItem.id + '-warning-km'" v-model="localItem.warningKm" name="anticipacion_km" type="number" min="0" step="1" :class="fieldClass" :disabled="!canEdit" />
            </FormField>
            <FormField label="Avisar antes (horas)" :for-id="'frequency-' + localItem.id + '-warning-hours'">
              <input :id="'frequency-' + localItem.id + '-warning-hours'" v-model="localItem.warningHours" name="anticipacion_horas" type="number" min="0" step="0.01" :class="fieldClass" :disabled="!canEdit" />
            </FormField>
            <FormField label="Avisar antes (días)" :for-id="'frequency-' + localItem.id + '-warning-days'">
              <input :id="'frequency-' + localItem.id + '-warning-days'" v-model="localItem.warningDays" name="anticipacion_dias" type="number" min="0" step="1" :class="fieldClass" :disabled="!canEdit" />
            </FormField>

            <FormField label="Prioridad" :for-id="'frequency-' + localItem.id + '-priority'">
              <select :id="'frequency-' + localItem.id + '-priority'" v-model="localItem.priority" name="prioridad" :class="fieldClass" :disabled="!canEdit">
                <option value="BAJA">Baja</option>
                <option value="MEDIA">Media</option>
                <option value="ALTA">Alta</option>
                <option value="CRITICA">Crítica</option>
              </select>
            </FormField>
            <label class="flex items-center gap-2 self-end pb-3 text-sm font-semibold text-ink md:col-span-2">
              <input v-model="localItem.active" name="activo" type="checkbox" value="1" :disabled="!canEdit" class="size-4 rounded border-border text-primary focus:ring-primary/20" />
              Servicio activo en la plantilla
            </label>

            <FormField label="Observaciones" :for-id="'frequency-' + localItem.id + '-notes'" class="md:col-span-3">
              <textarea :id="'frequency-' + localItem.id + '-notes'" v-model="localItem.notes" name="observaciones" rows="3" maxlength="1000" :class="fieldClass" :disabled="!canEdit"></textarea>
            </FormField>

            <p v-if="errorMessage" class="rounded-md bg-danger-subtle px-3 py-2 text-sm text-danger-strong md:col-span-3" role="alert">
              {{ errorMessage }}
            </p>
          </div>
          <footer class="flex flex-wrap items-center justify-end gap-2 border-t border-border-subtle bg-surface-subtle px-5 py-3">
            <button type="button" :class="secondaryButton" :disabled="submitting" @click="close">Cancelar</button>
            <button type="submit" :class="primaryButton" :disabled="submitting || !canEdit">
              {{ submitting ? 'Guardando…' : 'Guardar frecuencia' }}
            </button>
          </footer>
        </form>
      </div>
    </div>
  </Teleport>
</template>
