<script setup>
import BaseDialog from '../../../ui/BaseDialog.vue'
import CsrfInput from './CsrfInput.vue'
import FormField from './FormField.vue'
import { fieldClass, primaryButton, secondaryButton } from '../helpers.js'

defineProps({
  employee: { type: Object, required: true },
  expirationTypes: { type: Array, required: true },
  createUrl: { type: String, required: true },
  csrf: { type: Object, required: true },
})

const emit = defineEmits(['close'])
</script>

<template>
  <BaseDialog title="Registrar vencimiento" :description="employee.fullName" @close="emit('close')">

    <form method="post" :action="createUrl" class="grid gap-4 p-6">
      <CsrfInput :csrf="csrf" />
      <input type="hidden" name="sujeto_tipo" value="EMPLEADO" />
      <input type="hidden" name="sujeto_id" :value="employee.id" />
      <input type="hidden" name="return_to" value="/mantenimiento/empleados" />

      <FormField label="Tipo" for-id="employee-expiration-modal-type">
        <select id="employee-expiration-modal-type" name="tipo_vencimiento_id" required :class="fieldClass">
          <option value="">Seleccionar</option>
          <option v-for="type in expirationTypes" :key="type.id" :value="type.id">{{ type.name }}</option>
        </select>
      </FormField>

      <div class="grid gap-4 sm:grid-cols-2">
        <FormField label="Fecha de emisión (DD/MM/AAAA)" for-id="employee-expiration-modal-issued">
          <input id="employee-expiration-modal-issued" type="text" inputmode="numeric" autocomplete="off" name="fecha_emision" placeholder="DD/MM/AAAA" pattern="[0-9]{2}/[0-9]{2}/[0-9]{4}" :class="fieldClass" />
        </FormField>
        <FormField label="Fecha de vencimiento (DD/MM/AAAA)" for-id="employee-expiration-modal-date">
          <input id="employee-expiration-modal-date" type="text" inputmode="numeric" autocomplete="off" name="fecha_vencimiento" required placeholder="DD/MM/AAAA" pattern="[0-9]{2}/[0-9]{2}/[0-9]{4}" :class="fieldClass" />
        </FormField>
      </div>

      <FormField label="Documento" for-id="employee-expiration-modal-document">
        <input id="employee-expiration-modal-document" name="numero_documento" maxlength="100" :class="fieldClass" />
      </FormField>

      <FormField label="Observaciones" for-id="employee-expiration-modal-notes">
        <textarea id="employee-expiration-modal-notes" name="observaciones" maxlength="2000" rows="3" :class="fieldClass"></textarea>
      </FormField>

      <div class="flex justify-end gap-2 border-t border-border-subtle pt-4">
        <button type="button" :class="secondaryButton" @click="emit('close')">Cancelar</button>
        <button type="submit" :class="primaryButton">Registrar vencimiento</button>
      </div>
    </form>
  </BaseDialog>
</template>
