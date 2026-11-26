<script setup>
import BaseDialog from '../../../ui/BaseDialog.vue'
import { dangerButton } from '../../../ui/buttons.js'
import CsrfInput from './CsrfInput.vue'
import FormField from './FormField.vue'
import { fieldClass, secondaryButton } from '../helpers.js'

defineProps({
  employee: { type: Object, required: true },
  csrf: { type: Object, required: true },
})
const emit = defineEmits(['close'])
</script>

<template>
  <BaseDialog title="Dar de baja" :description="employee.fullName" @close="emit('close')">

    <form method="post" :action="employee.terminateUrl" class="grid gap-4 p-6">
      <CsrfInput :csrf="csrf" />
      <p class="rounded-lg bg-warning-soft p-3 text-sm text-warning-strong">
        La baja cerrará la asignación vigente del empleado, pero conservará el historial.
      </p>
      <FormField label="Fecha de baja" for-id="terminate-modal-date"><input id="terminate-modal-date" name="fecha_baja" type="date" :class="fieldClass" /></FormField>
      <FormField label="Motivo *" for-id="terminate-modal-reason"><textarea id="terminate-modal-reason" name="motivo_baja" required maxlength="500" rows="3" :class="fieldClass"></textarea></FormField>
      <div class="flex justify-end gap-2 border-t border-border-subtle pt-4">
        <button type="button" :class="secondaryButton" @click="emit('close')">Cancelar</button>
        <button type="submit" :class="dangerButton">Confirmar baja</button>
      </div>
    </form>
  </BaseDialog>
</template>
