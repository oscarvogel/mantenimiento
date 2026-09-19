<script setup>
import { computed, ref, watch } from 'vue'
import { ArrowPathIcon, CheckCircleIcon, ExclamationTriangleIcon, PaperClipIcon, XMarkIcon } from '@heroicons/vue/24/outline'
import CsrfInput from './CsrfInput.vue'
import FormField from './FormField.vue'
import { fieldClass, primaryButton, secondaryButton, dangerButton } from '../helpers.js'

const props = defineProps({
  expiration: { type: Object, required: true },
  csrf: { type: Object, required: true },
  renewUrl: { type: String, required: true },
  returnTo: { type: String, default: '' },
})

const emit = defineEmits(['close', 'renewed'])

const newDate = ref('')
const newIssueDate = ref('')
const documentNumber = ref('')
const notes = ref('')
const evidenceFile = ref(null)
const submitting = ref(false)
const errorMessage = ref('')
const fieldErrors = ref({})

const minDate = computed(() => props.expiration.fecha_vencimiento)

function reset() {
  newDate.value = ''
  newIssueDate.value = ''
  documentNumber.value = ''
  notes.value = ''
  evidenceFile.value = null
  errorMessage.value = ''
  fieldErrors.value = {}
}

watch(() => props.expiration, () => reset(), { immediate: true })

function handleFileChange(event) {
  const target = event.target
  evidenceFile.value = target && target.files && target.files.length > 0 ? target.files[0] : null
  if (evidenceFile.value && evidenceFile.value.size > 10 * 1024 * 1024) {
    fieldErrors.value.evidencia = 'La evidencia supera el tamano maximo permitido (10 MB).'
  } else {
    delete fieldErrors.value.evidencia
  }
}

function onSubmit() {
  errorMessage.value = ''
  fieldErrors.value = {}

  if (! newDate.value) {
    fieldErrors.value.fecha_vencimiento = 'Indica la nueva fecha de vencimiento.'
  } else if (newDate.value <= props.expiration.fecha_vencimiento) {
    fieldErrors.value.fecha_vencimiento = 'La nueva fecha debe ser estrictamente posterior a la actual.'
  }
  if (notes.value && notes.value.length > 2000) {
    fieldErrors.value.observaciones = 'Las observaciones admiten hasta 2000 caracteres.'
  }
  if (Object.keys(fieldErrors.value).length > 0) {
    errorMessage.value = 'Revisa los campos marcados antes de renovar.'
    return false
  }

  submitting.value = true
  emit('renewed', {
    previousDate: props.expiration.fecha_vencimiento,
    newDate: newDate.value,
  })
  return true
}
</script>

<template>
  <Teleport to="body">
    <div
      class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/55 p-3 backdrop-blur-[1px] sm:p-5"
      data-testid="renovar-vencimiento-modal"
      role="dialog"
      aria-modal="true"
      aria-labelledby="renovar-vencimiento-title"
      @click.self="emit('close')"
      @keydown.esc="emit('close')"
    >
      <section class="flex max-h-[calc(100vh-1.5rem)] w-full max-w-2xl flex-col overflow-hidden rounded-2xl border border-border bg-surface-raised shadow-2xl sm:max-h-[calc(100vh-2.5rem)]">
        <header class="flex items-start justify-between gap-4 border-b border-border-subtle px-5 py-4 sm:px-6">
          <div>
            <p class="text-xs font-bold uppercase tracking-[0.14em] text-primary">Renovar vencimiento</p>
            <h2 id="renovar-vencimiento-title" class="mt-1 text-xl font-bold text-ink">
              {{ expiration.tipo_nombre || 'Vencimiento' }}
            </h2>
            <p class="mt-1 text-sm text-ink-muted">
              Sujeto: {{ expiration.subject_name || (expiration.sujeto_tipo === 'EQUIPO' ? 'Equipo' : 'Empleado') }}.
            </p>
          </div>
          <button type="button" :class="secondaryButton" aria-label="Cerrar" @click="emit('close')">
            <XMarkIcon class="size-5" aria-hidden="true" />
          </button>
        </header>

        <form
          method="post"
          :action="renewUrl"
          enctype="multipart/form-data"
          class="flex flex-col gap-4 overflow-y-auto px-5 py-4 sm:px-6"
          @submit.prevent="onSubmit() && $event.target.submit()"
        >
          <CsrfInput :csrf="csrf" />
          <input type="hidden" name="sujeto_tipo" :value="expiration.sujeto_tipo" />
          <input v-if="returnTo" type="hidden" name="return_to" :value="returnTo" />

          <div
            v-if="errorMessage"
            class="flex items-start gap-3 rounded-xl border border-danger/30 bg-danger/10 px-3 py-2 text-sm text-danger"
            role="alert"
            data-testid="renovar-vencimiento-error"
          >
            <ExclamationTriangleIcon class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
            <span>{{ errorMessage }}</span>
          </div>

          <div class="rounded-xl border border-border-subtle bg-surface px-4 py-3 text-sm">
            <p class="text-xs uppercase tracking-[0.12em] text-ink-muted">Fecha vigente actual</p>
            <p class="mt-1 text-base font-semibold text-ink">{{ expiration.fecha_vencimiento }}</p>
          </div>

          <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <FormField label="Nueva fecha de vencimiento" for-id="renovar-vencimiento-fecha">
              <input
                id="renovar-vencimiento-fecha"
                type="date"
                name="fecha_vencimiento"
                v-model="newDate"
                :min="minDate"
                required
                :class="fieldClass"
                data-testid="renovar-vencimiento-fecha"
              />
              <span v-if="fieldErrors.fecha_vencimiento" class="mt-1 block text-xs text-danger">
                {{ fieldErrors.fecha_vencimiento }}
              </span>
            </FormField>
            <FormField label="Fecha de emision (opcional)" for-id="renovar-vencimiento-emision">
              <input
                id="renovar-vencimiento-emision"
                type="date"
                name="fecha_emision"
                v-model="newIssueDate"
                :class="fieldClass"
              />
            </FormField>
            <FormField label="Numero de documento (opcional)" for-id="renovar-vencimiento-documento">
              <input
                id="renovar-vencimiento-documento"
                type="text"
                name="numero_documento"
                v-model="documentNumber"
                maxlength="100"
                :class="fieldClass"
              />
            </FormField>
          </div>

          <FormField label="Observaciones (opcional)" for-id="renovar-vencimiento-observaciones">
            <textarea
              id="renovar-vencimiento-observaciones"
              name="observaciones"
              v-model="notes"
              rows="3"
              maxlength="2000"
              :class="fieldClass"
              data-testid="renovar-vencimiento-observaciones"
            ></textarea>
            <span v-if="fieldErrors.observaciones" class="mt-1 block text-xs text-danger">
              {{ fieldErrors.observaciones }}
            </span>
          </FormField>

          <FormField label="Evidencia / documento (opcional)" for-id="renovar-vencimiento-evidencia">
            <span class="mb-1 block text-xs text-ink-muted">
              Acepta PDF, JPG, PNG o WebP. Maximo 10 MB.
            </span>
            <input
              id="renovar-vencimiento-evidencia"
              type="file"
              name="evidencia"
              accept="application/pdf,image/jpeg,image/png,image/webp"
              :class="fieldClass"
              data-testid="renovar-vencimiento-evidencia"
              @change="handleFileChange"
            />
            <span v-if="fieldErrors.evidencia" class="mt-1 block text-xs text-danger">
              {{ fieldErrors.evidencia }}
            </span>
            <p v-if="evidenceFile" class="mt-2 inline-flex items-center gap-2 rounded-lg bg-surface px-2 py-1 text-xs text-ink-muted">
              <PaperClipIcon class="size-4" aria-hidden="true" />
              {{ evidenceFile.name }} ({{ Math.ceil(evidenceFile.size / 1024) }} KB)
            </p>
          </FormField>

          <footer class="flex flex-col-reverse items-stretch gap-2 border-t border-border-subtle bg-surface px-0 py-3 sm:flex-row sm:items-center sm:justify-end sm:px-0">
            <button type="button" :class="secondaryButton" :disabled="submitting" @click="emit('close')">
              Cancelar
            </button>
            <button
              type="submit"
              :class="primaryButton"
              :disabled="submitting"
              data-testid="renovar-vencimiento-submit"
            >
              <span v-if="! submitting" class="inline-flex items-center gap-2">
                <ArrowPathIcon class="size-4" aria-hidden="true" />
                Renovar
              </span>
              <span v-else>Renovando...</span>
            </button>
          </footer>

          <p class="sr-only" role="status" aria-live="polite">
            {{ submitting ? 'Enviando renovacion' : '' }}
          </p>
        </form>
      </section>
    </div>
  </Teleport>
</template>
