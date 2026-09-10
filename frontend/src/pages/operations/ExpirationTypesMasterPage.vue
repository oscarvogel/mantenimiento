<script setup>
import { ref } from 'vue'
import { PencilSquareIcon } from '@heroicons/vue/24/outline'
import CsrfInput from './components/CsrfInput.vue'
import FormField from './components/FormField.vue'
import PageHeading from './components/PageHeading.vue'
import PanelCard from './components/PanelCard.vue'
import { fieldClass, primaryButton, secondaryButton } from './helpers.js'

const props = defineProps({ data: { type: Object, required: true } })
const editing = ref(null)

const startEdit = (type) => {
  editing.value = { ...type }
}

const stopEdit = () => {
  editing.value = null
}
</script>

<template>
  <div>
    <PageHeading
      eyebrow="Maestros"
      title="Tipos de vencimiento"
      description="Administrá el catálogo general usado por equipos y empleados."
    />

    <PanelCard title="Nuevo tipo" class="mb-6">
      <form method="post" :action="data.routes.create" class="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
        <CsrfInput :csrf="data.csrf" />
        <input type="hidden" name="return_to" value="/mantenimiento/maestros/vencimientos" />

        <FormField label="Nombre" for-id="expiration-master-name">
          <input id="expiration-master-name" name="nombre" maxlength="100" required :class="fieldClass" />
        </FormField>

        <FormField label="Aplica a" for-id="expiration-master-applies">
          <select id="expiration-master-applies" name="aplica_a" :class="fieldClass">
            <option value="EQUIPO">Equipos</option>
            <option value="EMPLEADO">Empleados</option>
            <option value="AMBOS">Equipos y empleados</option>
          </select>
        </FormField>

        <FormField label="Avisar antes (días)" for-id="expiration-master-warning">
          <input id="expiration-master-warning" type="number" min="0" max="3650" name="dias_aviso_previo" value="30" required :class="fieldClass" />
        </FormField>

        <label class="flex items-end gap-2 pb-2 text-sm font-medium text-ink">
          <input type="checkbox" name="requiere_documento" value="1" />
          Requiere documento
        </label>

        <div class="flex items-end">
          <button type="submit" :class="primaryButton">Crear tipo</button>
        </div>
      </form>
    </PanelCard>

    <PanelCard title="Tipos configurados" :count="data.types.length" flush>
      <div v-if="data.types.length === 0" class="p-6 text-sm text-ink-muted">
        Todavía no hay tipos de vencimiento configurados.
      </div>

      <div v-else class="overflow-x-auto">
        <table class="w-full min-w-[52rem] text-left text-sm">
          <thead class="bg-surface-subtle text-xs uppercase tracking-wide text-ink-muted">
            <tr>
              <th class="px-5 py-3">Nombre</th>
              <th class="px-5 py-3">Aplica a</th>
              <th class="px-5 py-3">Aviso previo</th>
              <th class="px-5 py-3">Documento</th>
              <th class="px-5 py-3">Estado</th>
              <th class="px-5 py-3 text-right">Acciones</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border-subtle">
            <tr v-for="type in data.types" :key="type.id">
              <td class="px-5 py-4 font-semibold text-ink">{{ type.name }}</td>
              <td class="px-5 py-4 text-ink-muted">
                {{ type.appliesTo === 'AMBOS' ? 'Equipos y empleados' : type.appliesTo === 'EQUIPO' ? 'Equipos' : 'Empleados' }}
              </td>
              <td class="px-5 py-4 text-ink-muted">{{ type.warningDays }} días</td>
              <td class="px-5 py-4 text-ink-muted">{{ type.requiresDocument ? 'Sí' : 'No' }}</td>
              <td class="px-5 py-4">
                <span :class="type.active ? 'bg-success-soft text-success-strong' : 'bg-surface-subtle text-ink-muted'" class="rounded-full px-2.5 py-1 text-xs font-semibold">
                  {{ type.active ? 'Activo' : 'Inactivo' }}
                </span>
              </td>
              <td class="px-5 py-4">
                <div class="flex justify-end gap-2">
                  <button type="button" :class="secondaryButton" @click="startEdit(type)">
                    <PencilSquareIcon class="mr-1.5 size-4" aria-hidden="true" />
                    Editar
                  </button>
                  <form method="post" :action="type.toggleUrl">
                    <CsrfInput :csrf="data.csrf" />
                    <input type="hidden" name="return_to" value="/mantenimiento/maestros/vencimientos" />
                    <button type="submit" :class="secondaryButton">{{ type.active ? 'Inactivar' : 'Activar' }}</button>
                  </form>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </PanelCard>

    <Teleport to="body">
      <div v-if="editing" class="fixed inset-0 z-[80] flex items-center justify-center bg-black/45 p-4" role="presentation" @mousedown.self="stopEdit">
        <section role="dialog" aria-modal="true" aria-labelledby="edit-expiration-type-title" class="w-full max-w-lg rounded-xl bg-white shadow-2xl">
          <header class="border-b border-border-subtle px-6 py-4">
            <h2 id="edit-expiration-type-title" class="text-lg font-bold text-ink">Editar tipo de vencimiento</h2>
          </header>
          <form method="post" :action="editing.updateUrl" class="grid gap-4 p-6">
            <CsrfInput :csrf="data.csrf" />
            <input type="hidden" name="return_to" value="/mantenimiento/maestros/vencimientos" />

            <FormField label="Nombre" for-id="edit-expiration-type-name">
              <input id="edit-expiration-type-name" name="nombre" maxlength="100" required :value="editing.name" :class="fieldClass" />
            </FormField>

            <FormField label="Aplica a" for-id="edit-expiration-type-applies">
              <select id="edit-expiration-type-applies" name="aplica_a" :class="fieldClass">
                <option value="EQUIPO" :selected="editing.appliesTo === 'EQUIPO'">Equipos</option>
                <option value="EMPLEADO" :selected="editing.appliesTo === 'EMPLEADO'">Empleados</option>
                <option value="AMBOS" :selected="editing.appliesTo === 'AMBOS'">Equipos y empleados</option>
              </select>
            </FormField>

            <FormField label="Avisar antes (días)" for-id="edit-expiration-type-warning">
              <input id="edit-expiration-type-warning" type="number" min="0" max="3650" name="dias_aviso_previo" required :value="editing.warningDays" :class="fieldClass" />
            </FormField>

            <label class="flex items-center gap-2 text-sm font-medium text-ink">
              <input type="checkbox" name="requiere_documento" value="1" :checked="editing.requiresDocument" />
              Requiere documento
            </label>

            <div class="flex justify-end gap-2 border-t border-border-subtle pt-4">
              <button type="button" :class="secondaryButton" @click="stopEdit">Cancelar</button>
              <button type="submit" :class="primaryButton">Guardar cambios</button>
            </div>
          </form>
        </section>
      </div>
    </Teleport>
  </div>
</template>
