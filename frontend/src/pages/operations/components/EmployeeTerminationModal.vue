<script setup>
import { XMarkIcon } from '@heroicons/vue/24/outline'
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
  <Teleport to="body">
    <div class="fixed inset-0 z-[80] flex items-center justify-center bg-black/45 p-4" role="presentation" @mousedown.self="emit('close')">
      <section role="dialog" aria-modal="true" aria-labelledby="terminate-modal-title" class="w-full max-w-lg rounded-xl bg-surface-raised shadow-2xl">
        <header class="flex items-start justify-between border-b border-border-subtle px-6 py-4">
          <div>
            <h2 id="terminate-modal-title" class="text-lg font-bold text-ink">Dar de baja</h2>
            <p class="mt-1 text-sm text-ink-muted">{{ employee.fullName }}</p>
          </div>
          <button type="button" class="rounded-md p-2 text-ink-muted hover:bg-surface-subtle" aria-label="Cerrar" @click="emit('close')">
            <XMarkIcon class="size-5" aria-hidden="true" />
          </button>
        </header>

        <form method="post" :action="employee.terminateUrl" class="grid gap-4 p-6">
          <CsrfInput :csrf="csrf" />
          <p class="rounded-lg bg-warning-soft p-3 text-sm text-warning-strong">
            La baja cerrará la asignación vigente del empleado, pero conservará el historial.
          </p>
          <FormField label="Fecha de baja" for-id="terminate-modal-date"><input id="terminate-modal-date" name="fecha_baja" type="date" :class="fieldClass" /></FormField>
          <FormField label="Motivo *" for-id="terminate-modal-reason"><textarea id="terminate-modal-reason" name="motivo_baja" required maxlength="500" rows="3" :class="fieldClass"></textarea></FormField>
          <div class="flex justify-end gap-2 border-t border-border-subtle pt-4">
            <button type="button" :class="secondaryButton" @click="emit('close')">Cancelar</button>
            <button type="submit" class="inline-flex items-center justify-center rounded-md border border-danger/30 px-4 py-2 text-sm font-semibold text-danger-strong hover:bg-danger/5">Confirmar baja</button>
          </div>
        </form>
      </section>
    </div>
  </Teleport>
</template>
