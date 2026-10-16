<script setup>
import { XMarkIcon } from '@heroicons/vue/24/outline'
import CsrfInput from './CsrfInput.vue'
import FormField from './FormField.vue'
import { fieldClass, primaryButton, secondaryButton, formatDateDmy } from '../helpers.js'

defineProps({
  expiration: { type: Object, required: true },
  csrf: { type: Object, required: true },
  returnTo: { type: String, required: true },
})

const emit = defineEmits(['close'])
</script>

<template>
  <Teleport to="body">
    <div class="fixed inset-0 z-[80] flex items-center justify-center bg-black/45 p-4" role="presentation" @mousedown.self="emit('close')">
      <section role="dialog" aria-modal="true" aria-labelledby="edit-expiration-modal-title" class="w-full max-w-xl rounded-xl bg-surface-raised shadow-2xl">
        <header class="flex items-start justify-between border-b border-border-subtle px-6 py-4">
          <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-ink-muted">Corrección de datos</p>
            <h2 id="edit-expiration-modal-title" class="mt-1 text-lg font-bold text-ink">Editar {{ expiration.typeName }}</h2>
            <p class="mt-1 text-sm text-ink-muted">{{ expiration.subjectName }}</p>
          </div>
          <button type="button" class="rounded-md p-2 text-ink-muted hover:bg-surface-subtle" aria-label="Cerrar" @click="emit('close')">
            <XMarkIcon class="size-5" aria-hidden="true" />
          </button>
        </header>

        <div class="border-b border-border-subtle bg-surface-subtle px-6 py-4">
          <p class="text-sm text-ink-muted">Usá día/mes/año. Ejemplo: <strong class="text-ink">12/10/2027</strong> es 12 de octubre de 2027.</p>
        </div>

        <form method="post" :action="expiration.updateUrl" class="grid gap-4 p-6">
          <CsrfInput :csrf="csrf" />
          <input type="hidden" name="return_to" :value="returnTo" />

          <div class="grid gap-4 sm:grid-cols-2">
            <FormField label="Fecha de emisión (DD/MM/AAAA)" for-id="edit-expiration-issued">
              <input id="edit-expiration-issued" type="text" inputmode="numeric" autocomplete="off" name="fecha_emision" :value="formatDateDmy(expiration.issuedAt)" placeholder="DD/MM/AAAA" pattern="[0-9]{2}/[0-9]{2}/[0-9]{4}" :class="fieldClass" />
            </FormField>
            <FormField label="Fecha de vencimiento (DD/MM/AAAA)" for-id="edit-expiration-date">
              <input id="edit-expiration-date" type="text" inputmode="numeric" autocomplete="off" name="fecha_vencimiento" required :value="formatDateDmy(expiration.expiresAt)" placeholder="DD/MM/AAAA" pattern="[0-9]{2}/[0-9]{2}/[0-9]{4}" :class="fieldClass" />
            </FormField>
          </div>

          <FormField label="Documento" for-id="edit-expiration-document">
            <input id="edit-expiration-document" name="numero_documento" maxlength="100" :value="expiration.documentNumber" placeholder="Documento" :class="fieldClass" />
          </FormField>
          <FormField label="Observaciones" for-id="edit-expiration-notes">
            <textarea id="edit-expiration-notes" name="observaciones" maxlength="2000" rows="3" :value="expiration.notes" :class="fieldClass"></textarea>
          </FormField>

          <div class="flex justify-end gap-2 border-t border-border-subtle pt-4">
            <button type="button" :class="secondaryButton" @click="emit('close')">Cancelar</button>
            <button type="submit" :class="primaryButton">Guardar cambios</button>
          </div>
        </form>
      </section>
    </div>
  </Teleport>
</template>
