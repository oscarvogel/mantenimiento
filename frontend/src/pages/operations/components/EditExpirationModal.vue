<script setup>
import BaseDialog from '../../../ui/BaseDialog.vue'
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
  <BaseDialog eyebrow="Corrección de datos" :title="`Editar ${expiration.typeName}`" :description="expiration.subjectName" size="lg" @close="emit('close')">

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
  </BaseDialog>
</template>
