<script setup>
import { computed } from 'vue'
import { XMarkIcon } from '@heroicons/vue/24/outline'
import CsrfInput from './CsrfInput.vue'
import FormField from './FormField.vue'
import { fieldClass, primaryButton, secondaryButton } from '../helpers.js'

const props = defineProps({
  expiration: { type: Object, required: true },
  csrf: { type: Object, required: true },
  returnTo: { type: String, required: true },
})

const emit = defineEmits(['close'])

const minimumExpirationDate = computed(() => {
  const current = String(props.expiration.expiresAt || '')
  if (!/^\d{4}-\d{2}-\d{2}$/.test(current)) return ''
  const date = new Date(`${current}T12:00:00`)
  if (Number.isNaN(date.getTime())) return ''
  date.setDate(date.getDate() + 1)
  return date.toISOString().slice(0, 10)
})

const originLabel = computed(() => {
  const origin = String(props.expiration.origin || '').trim().toUpperCase()
  if (!origin) return null
  return origin === 'MANUAL' ? 'Carga manual' : `Origen: ${origin}`
})
</script>

<template>
  <Teleport to="body">
    <div
      class="fixed inset-0 z-[80] flex items-center justify-center bg-black/45 p-4"
      role="presentation"
      @mousedown.self="emit('close')"
    >
      <section
        role="dialog"
        aria-modal="true"
        aria-labelledby="renew-expiration-modal-title"
        class="w-full max-w-xl rounded-xl bg-surface-raised shadow-2xl"
      >
        <header class="flex items-start justify-between border-b border-border-subtle px-6 py-4">
          <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-ink-muted">Renovación</p>
            <h2 id="renew-expiration-modal-title" class="mt-1 text-lg font-bold text-ink">
              {{ expiration.typeName }}
            </h2>
            <p class="mt-1 text-sm text-ink-muted">{{ expiration.subjectName }}</p>
          </div>
          <button
            type="button"
            class="rounded-md p-2 text-ink-muted hover:bg-surface-subtle"
            aria-label="Cerrar"
            @click="emit('close')"
          >
            <XMarkIcon class="size-5" aria-hidden="true" />
          </button>
        </header>

        <div class="border-b border-border-subtle bg-surface-subtle px-6 py-4">
          <div class="grid gap-3 text-sm sm:grid-cols-2">
            <div>
              <span class="block text-xs font-semibold uppercase tracking-wide text-ink-muted">Vencimiento actual</span>
              <strong class="mt-1 block text-ink">{{ expiration.expiresAt }}</strong>
            </div>
            <div>
              <span class="block text-xs font-semibold uppercase tracking-wide text-ink-muted">Sucursal</span>
              <strong class="mt-1 block text-ink">{{ expiration.branchName || 'Sin sucursal' }}</strong>
            </div>
          </div>
          <p v-if="originLabel" class="mt-3 text-xs text-ink-muted">{{ originLabel }}</p>
          <p class="mt-3 text-sm text-ink-muted">
            Se creará una nueva vigencia. La anterior quedará conservada en el historial y no será sobrescrita.
          </p>
        </div>

        <form method="post" :action="expiration.renewUrl" class="grid gap-4 p-6">
          <CsrfInput :csrf="csrf" />
          <input type="hidden" name="return_to" :value="returnTo" />

          <div class="grid gap-4 sm:grid-cols-2">
            <FormField label="Fecha de emisión" for-id="renew-expiration-issued">
              <input
                id="renew-expiration-issued"
                type="date"
                name="fecha_emision"
                :class="fieldClass"
              />
            </FormField>
            <FormField
              label="Nueva fecha de vencimiento"
              for-id="renew-expiration-date"
              hint="Debe ser posterior al vencimiento actual."
            >
              <input
                id="renew-expiration-date"
                type="date"
                name="fecha_vencimiento"
                required
                :min="minimumExpirationDate || undefined"
                :class="fieldClass"
              />
            </FormField>
          </div>

          <FormField
            label="Documento"
            for-id="renew-expiration-document"
            :hint="expiration.requiresDocument ? 'Obligatorio para este tipo de vencimiento.' : 'Opcional.'"
          >
            <input
              id="renew-expiration-document"
              name="numero_documento"
              maxlength="100"
              :required="expiration.requiresDocument"
              :class="fieldClass"
            />
          </FormField>

          <FormField label="Observaciones" for-id="renew-expiration-notes">
            <textarea
              id="renew-expiration-notes"
              name="observaciones"
              maxlength="2000"
              rows="3"
              :class="fieldClass"
            ></textarea>
          </FormField>

          <div class="flex justify-end gap-2 border-t border-border-subtle pt-4">
            <button type="button" :class="secondaryButton" @click="emit('close')">Cancelar</button>
            <button type="submit" :class="primaryButton">Guardar renovación</button>
          </div>
        </form>
      </section>
    </div>
  </Teleport>
</template>
