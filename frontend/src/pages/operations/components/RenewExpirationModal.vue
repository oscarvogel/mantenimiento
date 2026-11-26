<script setup>
import { computed } from 'vue'
import BaseDialog from '../../../ui/BaseDialog.vue'
import CsrfInput from './CsrfInput.vue'
import FormField from './FormField.vue'
import { fieldClass, formatDateDmy, primaryButton, secondaryButton } from '../helpers.js'

const props = defineProps({
  expiration: { type: Object, required: true },
  csrf: { type: Object, required: true },
  returnTo: { type: String, required: true },
})

const emit = defineEmits(['close'])

const originLabel = computed(() => {
  const origin = String(props.expiration.origin || '').trim().toUpperCase()
  if (!origin) return null
  return origin === 'MANUAL' ? 'Carga manual' : `Origen: ${humanizeCode(origin)}`
})
</script>

<template>
  <BaseDialog eyebrow="Renovación" :title="expiration.typeName" :description="expiration.subjectName" size="lg" @close="emit('close')">
        <div class="border-b border-border-subtle bg-surface-subtle px-6 py-4">
          <div class="grid gap-3 text-sm sm:grid-cols-2">
            <div>
              <span class="block text-xs font-semibold uppercase tracking-wide text-ink-muted">Vencimiento actual</span>
              <strong class="mt-1 block text-ink">{{ formatDateDmy(expiration.expiresAt) }}</strong>
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
            <FormField label="Fecha de emisión (DD/MM/AAAA)" for-id="renew-expiration-issued">
              <input
                id="renew-expiration-issued"
                type="text"
                inputmode="numeric"
                autocomplete="off"
                name="fecha_emision"
                placeholder="DD/MM/AAAA"
                pattern="[0-9]{2}/[0-9]{2}/[0-9]{4}"
                :class="fieldClass"
              />
            </FormField>
            <FormField
              label="Nueva fecha de vencimiento (DD/MM/AAAA)"
              for-id="renew-expiration-date"
              hint="Día/mes/año. Debe ser posterior al vencimiento actual."
            >
              <input
                id="renew-expiration-date"
                type="text"
                inputmode="numeric"
                autocomplete="off"
                name="fecha_vencimiento"
                required
                placeholder="DD/MM/AAAA"
                pattern="[0-9]{2}/[0-9]{2}/[0-9]{4}"
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
  </BaseDialog>
</template>
