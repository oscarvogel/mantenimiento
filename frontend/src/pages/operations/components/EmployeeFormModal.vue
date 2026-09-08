<script setup>
import { computed } from 'vue'
import { XMarkIcon } from '@heroicons/vue/24/outline'
import CsrfInput from './CsrfInput.vue'
import FormField from './FormField.vue'
import { fieldClass, primaryButton, secondaryButton } from '../helpers.js'

const props = defineProps({
  employee: { type: Object, default: null },
  createUrl: { type: String, required: true },
  csrf: { type: Object, required: true },
})
const emit = defineEmits(['close'])

const isEditing = computed(() => props.employee !== null)
const action = computed(() => props.employee?.updateUrl || props.createUrl)
const title = computed(() => isEditing.value ? 'Editar empleado' : 'Nuevo empleado')
</script>

<template>
  <Teleport to="body">
    <div class="fixed inset-0 z-[80] flex items-center justify-center bg-black/45 p-4" role="presentation" @mousedown.self="emit('close')">
      <section
        role="dialog"
        aria-modal="true"
        aria-labelledby="employee-modal-title"
        class="max-h-[92vh] w-full max-w-3xl overflow-y-auto rounded-xl bg-white shadow-2xl"
      >
        <header class="sticky top-0 z-10 flex items-center justify-between border-b border-border-subtle bg-white px-6 py-4">
          <div>
            <h2 id="employee-modal-title" class="text-lg font-bold text-ink">{{ title }}</h2>
            <p class="mt-1 text-sm text-ink-muted">
              {{ isEditing ? 'Actualizá los datos del empleado seleccionado.' : 'Completá los datos básicos. Documento, CUIL y legajo pueden cargarse después.' }}
            </p>
          </div>
          <button type="button" class="rounded-md p-2 text-ink-muted hover:bg-surface-subtle hover:text-ink" aria-label="Cerrar" @click="emit('close')">
            <XMarkIcon class="size-5" aria-hidden="true" />
          </button>
        </header>

        <form method="post" :action="action" enctype="multipart/form-data" class="grid gap-4 p-6 lg:grid-cols-3">
          <CsrfInput :csrf="csrf" />
          <div class="lg:col-span-3 flex items-center gap-4 rounded-lg border border-border-subtle bg-surface-subtle/40 p-4">
            <div class="flex size-16 shrink-0 items-center justify-center overflow-hidden rounded-full border border-border-subtle bg-white text-lg font-bold text-ink-muted">
              <img v-if="employee?.photoUrl" :src="employee.photoUrl" :alt="`Foto de ${employee.fullName}`" class="h-full w-full object-cover" />
              <span v-else>{{ (employee?.firstName || 'N').slice(0, 1).toUpperCase() }}</span>
            </div>
            <FormField label="Foto del empleado" for-id="modal-employee-photo" class="flex-1">
              <input id="modal-employee-photo" name="foto" type="file" accept="image/jpeg,image/png,image/webp" :class="fieldClass" />
              <p class="mt-1 text-xs text-ink-muted">JPG, PNG o WEBP. Máximo 5 MB.</p>
            </FormField>
          </div>
          <FormField label="Nombre *" for-id="modal-employee-name"><input id="modal-employee-name" name="nombre" :value="employee?.firstName || ''" required maxlength="100" autofocus :class="fieldClass" /></FormField>
          <FormField label="Apellido" for-id="modal-employee-lastname"><input id="modal-employee-lastname" name="apellido" :value="employee?.lastName || ''" maxlength="100" :class="fieldClass" /></FormField>
          <FormField label="Documento" for-id="modal-employee-document"><input id="modal-employee-document" name="documento" :value="employee?.document || ''" maxlength="30" :class="fieldClass" /></FormField>
          <FormField label="CUIL" for-id="modal-employee-cuil"><input id="modal-employee-cuil" name="cuil" :value="employee?.cuil || ''" maxlength="30" :class="fieldClass" /></FormField>
          <FormField label="Legajo" for-id="modal-employee-number"><input id="modal-employee-number" name="legajo" :value="employee?.employeeNumber || ''" maxlength="50" :class="fieldClass" /></FormField>
          <FormField label="Fecha de ingreso" for-id="modal-employee-hired"><input id="modal-employee-hired" name="fecha_ingreso" type="date" :value="employee?.hiredAt || ''" :class="fieldClass" /></FormField>
          <FormField label="Teléfono" for-id="modal-employee-phone"><input id="modal-employee-phone" name="telefono" :value="employee?.phone || ''" maxlength="50" :class="fieldClass" /></FormField>
          <FormField label="Email" for-id="modal-employee-email"><input id="modal-employee-email" name="email" type="email" :value="employee?.email || ''" maxlength="150" :class="fieldClass" /></FormField>
          <FormField label="Observaciones" for-id="modal-employee-notes" class="lg:col-span-3"><textarea id="modal-employee-notes" name="observaciones" rows="3" maxlength="1000" :value="employee?.notes || ''" :class="fieldClass"></textarea></FormField>

          <div class="lg:col-span-3 flex justify-end gap-2 border-t border-border-subtle pt-4">
            <button type="button" :class="secondaryButton" @click="emit('close')">Cancelar</button>
            <button type="submit" :class="primaryButton">{{ isEditing ? 'Guardar cambios' : 'Crear empleado' }}</button>
          </div>
        </form>
      </section>
    </div>
  </Teleport>
</template>
