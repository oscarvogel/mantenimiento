<script setup>
import { XMarkIcon } from '@heroicons/vue/24/outline'
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
  <Teleport to="body">
    <div class="fixed inset-0 z-[80] flex items-center justify-center bg-black/45 p-4" role="presentation" @mousedown.self="emit('close')">
      <section role="dialog" aria-modal="true" aria-labelledby="employee-expiration-modal-title" class="w-full max-w-lg rounded-xl bg-white shadow-2xl">
        <header class="flex items-start justify-between border-b border-border-subtle px-6 py-4">
          <div>
            <h2 id="employee-expiration-modal-title" class="text-lg font-bold text-ink">Registrar vencimiento</h2>
            <p class="mt-1 text-sm text-ink-muted">{{ employee.fullName }}</p>
          </div>
          <button type="button" class="rounded-md p-2 text-ink-muted hover:bg-surface-subtle" aria-label="Cerrar" @click="emit('close')">
            <XMarkIcon class="size-5" aria-hidden="true" />
          </button>
        </header>

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
            <FormField label="Fecha de emisión" for-id="employee-expiration-modal-issued">
              <input id="employee-expiration-modal-issued" type="date" name="fecha_emision" :class="fieldClass" />
            </FormField>
            <FormField label="Fecha de vencimiento" for-id="employee-expiration-modal-date">
              <input id="employee-expiration-modal-date" type="date" name="fecha_vencimiento" required :class="fieldClass" />
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
      </section>
    </div>
  </Teleport>
</template>
