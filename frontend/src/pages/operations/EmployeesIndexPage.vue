<script setup>
import { computed, ref } from 'vue'
import { MagnifyingGlassIcon, PencilSquareIcon, UserMinusIcon, UserPlusIcon } from '@heroicons/vue/24/outline'
import CsrfInput from './components/CsrfInput.vue'
import EmptyState from './components/EmptyState.vue'
import FormField from './components/FormField.vue'
import PageHeading from './components/PageHeading.vue'
import PanelCard from './components/PanelCard.vue'
import { fieldClass, primaryButton, secondaryButton } from './helpers.js'

const props = defineProps({ data: { type: Object, required: true } })

const showCreate = ref(false)
const editingId = ref(null)
const terminatingId = ref(null)

const activeCount = computed(() => props.data.employees.filter((employee) => employee.active).length)

const toggleEdit = (employeeId) => {
  editingId.value = editingId.value === employeeId ? null : employeeId
  terminatingId.value = null
}

const toggleTerminate = (employeeId) => {
  terminatingId.value = terminatingId.value === employeeId ? null : employeeId
  editingId.value = null
}
</script>

<template>
  <div>
    <PageHeading
      eyebrow="Gestión"
      title="Empleados y choferes"
      description="Buscá, consultá y administrá empleados de la empresa. Las asignaciones a móviles conservan siempre su historial."
    />

    <PanelCard title="Buscar empleados" class="mb-6">
      <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <form method="get" :action="data.routes.index" class="grid flex-1 gap-3 md:grid-cols-[minmax(18rem,1fr)_12rem_auto] md:items-end">
          <FormField label="Nombre, documento o legajo" for-id="employee-search">
            <div class="relative">
              <MagnifyingGlassIcon class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-ink-muted" aria-hidden="true" />
              <input
                id="employee-search"
                name="q"
                :value="data.filters.q"
                placeholder="Ej.: Ariel Rodríguez, 30111222 o CHO-12"
                :class="`${fieldClass} pl-9`"
              />
            </div>
          </FormField>
          <FormField label="Estado" for-id="employee-status">
            <select id="employee-status" name="estado" :class="fieldClass">
              <option value="activos" :selected="data.filters.status === 'activos'">Activos</option>
              <option value="baja" :selected="data.filters.status === 'baja'">Dados de baja</option>
              <option value="todos" :selected="data.filters.status === 'todos'">Todos</option>
            </select>
          </FormField>
          <button type="submit" :class="primaryButton">Buscar</button>
        </form>

        <button
          v-if="data.canEdit"
          type="button"
          :class="secondaryButton"
          class="shrink-0"
          @click="showCreate = !showCreate"
        >
          <UserPlusIcon class="mr-2 size-5" aria-hidden="true" />
          {{ showCreate ? 'Cerrar alta' : 'Nuevo empleado' }}
        </button>
      </div>

      <p class="mt-3 text-sm text-ink-muted">
        {{ data.employees.length }} resultado{{ data.employees.length === 1 ? '' : 's' }} · {{ activeCount }} activo{{ activeCount === 1 ? '' : 's' }}
      </p>
    </PanelCard>

    <PanelCard v-if="data.canEdit && showCreate" title="Alta de empleado" class="mb-6">
      <form method="post" :action="data.routes.create" class="grid gap-4 lg:grid-cols-3">
        <CsrfInput :csrf="data.csrf" />
        <FormField label="Nombre *" for-id="employee-name"><input id="employee-name" name="nombre" required maxlength="100" :class="fieldClass" /></FormField>
        <FormField label="Apellido" for-id="employee-lastname"><input id="employee-lastname" name="apellido" maxlength="100" :class="fieldClass" /></FormField>
        <FormField label="Documento" for-id="employee-document"><input id="employee-document" name="documento" maxlength="30" :class="fieldClass" /></FormField>
        <FormField label="CUIL" for-id="employee-cuil"><input id="employee-cuil" name="cuil" maxlength="30" :class="fieldClass" /></FormField>
        <FormField label="Legajo" for-id="employee-number"><input id="employee-number" name="legajo" maxlength="50" :class="fieldClass" /></FormField>
        <FormField label="Fecha de ingreso" for-id="employee-hired"><input id="employee-hired" name="fecha_ingreso" type="date" :class="fieldClass" /></FormField>
        <FormField label="Teléfono" for-id="employee-phone"><input id="employee-phone" name="telefono" maxlength="50" :class="fieldClass" /></FormField>
        <FormField label="Email" for-id="employee-email"><input id="employee-email" name="email" type="email" maxlength="150" :class="fieldClass" /></FormField>
        <FormField label="Observaciones" for-id="employee-notes" class="lg:col-span-3"><textarea id="employee-notes" name="observaciones" rows="2" maxlength="1000" :class="fieldClass"></textarea></FormField>
        <div class="lg:col-span-3 flex justify-end">
          <button type="submit" :class="primaryButton"><UserPlusIcon class="mr-2 size-5" aria-hidden="true" />Guardar empleado</button>
        </div>
      </form>
    </PanelCard>

    <PanelCard title="Resultados" :count="data.employees.length" flush>
      <EmptyState
        v-if="data.employees.length === 0"
        title="No encontramos empleados"
        description="Probá con otro nombre, documento, legajo o cambiá el filtro de estado."
      />

      <template v-else>
        <div class="hidden overflow-x-auto md:block">
          <table class="w-full min-w-[58rem] text-left text-sm">
            <thead class="bg-surface-subtle text-xs uppercase tracking-wide text-ink-muted">
              <tr>
                <th class="px-5 py-3">Empleado</th>
                <th class="px-5 py-3">Documento</th>
                <th class="px-5 py-3">Legajo</th>
                <th class="px-5 py-3">Contacto</th>
                <th class="px-5 py-3">Estado</th>
                <th class="px-5 py-3 text-right">Acciones</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-border-subtle">
              <template v-for="employee in data.employees" :key="employee.id">
                <tr class="hover:bg-brand-50/50">
                  <td class="px-5 py-4">
                    <div class="font-semibold text-ink">{{ employee.fullName }}</div>
                    <div v-if="employee.importedIncomplete" class="mt-1 text-xs font-medium text-warning-strong">Datos incompletos</div>
                  </td>
                  <td class="px-5 py-4 text-ink-muted">{{ employee.document || '—' }}</td>
                  <td class="px-5 py-4 text-ink-muted">{{ employee.employeeNumber || '—' }}</td>
                  <td class="px-5 py-4 text-ink-muted">
                    <div>{{ employee.phone || '—' }}</div>
                    <div v-if="employee.email" class="text-xs">{{ employee.email }}</div>
                  </td>
                  <td class="px-5 py-4">
                    <span
                      :class="employee.active ? 'bg-success-soft text-success-strong' : 'bg-surface-subtle text-ink-muted'"
                      class="rounded-full px-2.5 py-1 text-xs font-semibold"
                    >
                      {{ employee.active ? 'Activo' : 'Baja' }}
                    </span>
                    <div v-if="!employee.active && employee.terminatedAt" class="mt-1 text-xs text-ink-muted">{{ employee.terminatedAt }}</div>
                  </td>
                  <td class="px-5 py-4">
                    <div v-if="data.canEdit && employee.active" class="flex justify-end gap-2">
                      <button type="button" :class="secondaryButton" @click="toggleEdit(employee.id)">
                        <PencilSquareIcon class="mr-1.5 size-4" aria-hidden="true" />Editar
                      </button>
                      <button type="button" class="inline-flex items-center rounded-md border border-danger/30 px-3 py-2 text-sm font-semibold text-danger-strong hover:bg-danger/5" @click="toggleTerminate(employee.id)">
                        <UserMinusIcon class="mr-1.5 size-4" aria-hidden="true" />Baja
                      </button>
                    </div>
                    <div v-else class="text-right text-xs text-ink-muted">Sin acciones</div>
                  </td>
                </tr>

                <tr v-if="editingId === employee.id">
                  <td colspan="6" class="bg-surface-subtle/50 px-5 py-5">
                    <form method="post" :action="employee.updateUrl" class="grid gap-4 lg:grid-cols-3">
                      <CsrfInput :csrf="data.csrf" />
                      <FormField label="Nombre *" :for-id="`edit-name-${employee.id}`"><input :id="`edit-name-${employee.id}`" name="nombre" :value="employee.firstName" required maxlength="100" :class="fieldClass" /></FormField>
                      <FormField label="Apellido" :for-id="`edit-lastname-${employee.id}`"><input :id="`edit-lastname-${employee.id}`" name="apellido" :value="employee.lastName" maxlength="100" :class="fieldClass" /></FormField>
                      <FormField label="Documento" :for-id="`edit-document-${employee.id}`"><input :id="`edit-document-${employee.id}`" name="documento" :value="employee.document" maxlength="30" :class="fieldClass" /></FormField>
                      <FormField label="CUIL" :for-id="`edit-cuil-${employee.id}`"><input :id="`edit-cuil-${employee.id}`" name="cuil" :value="employee.cuil" maxlength="30" :class="fieldClass" /></FormField>
                      <FormField label="Legajo" :for-id="`edit-number-${employee.id}`"><input :id="`edit-number-${employee.id}`" name="legajo" :value="employee.employeeNumber" maxlength="50" :class="fieldClass" /></FormField>
                      <FormField label="Fecha de ingreso" :for-id="`edit-hired-${employee.id}`"><input :id="`edit-hired-${employee.id}`" name="fecha_ingreso" type="date" :value="employee.hiredAt" :class="fieldClass" /></FormField>
                      <FormField label="Teléfono" :for-id="`edit-phone-${employee.id}`"><input :id="`edit-phone-${employee.id}`" name="telefono" :value="employee.phone" maxlength="50" :class="fieldClass" /></FormField>
                      <FormField label="Email" :for-id="`edit-email-${employee.id}`"><input :id="`edit-email-${employee.id}`" name="email" type="email" :value="employee.email" maxlength="150" :class="fieldClass" /></FormField>
                      <FormField label="Observaciones" :for-id="`edit-notes-${employee.id}`" class="lg:col-span-3"><textarea :id="`edit-notes-${employee.id}`" name="observaciones" rows="2" maxlength="1000" :value="employee.notes || ''" :class="fieldClass"></textarea></FormField>
                      <div class="lg:col-span-3 flex justify-end gap-2">
                        <button type="button" :class="secondaryButton" @click="editingId = null">Cancelar</button>
                        <button type="submit" :class="primaryButton">Guardar cambios</button>
                      </div>
                    </form>
                  </td>
                </tr>

                <tr v-if="terminatingId === employee.id">
                  <td colspan="6" class="bg-danger/5 px-5 py-5">
                    <form method="post" :action="employee.terminateUrl" class="grid gap-4 md:grid-cols-[12rem_1fr_auto_auto] md:items-end">
                      <CsrfInput :csrf="data.csrf" />
                      <FormField label="Fecha de baja" :for-id="`terminate-date-${employee.id}`"><input :id="`terminate-date-${employee.id}`" name="fecha_baja" type="date" :class="fieldClass" /></FormField>
                      <FormField label="Motivo *" :for-id="`terminate-reason-${employee.id}`"><input :id="`terminate-reason-${employee.id}`" name="motivo_baja" required maxlength="500" :class="fieldClass" /></FormField>
                      <button type="button" :class="secondaryButton" @click="terminatingId = null">Cancelar</button>
                      <button type="submit" class="inline-flex items-center justify-center rounded-md border border-danger/30 px-4 py-2 text-sm font-semibold text-danger-strong hover:bg-danger/5">Confirmar baja</button>
                    </form>
                  </td>
                </tr>
              </template>
            </tbody>
          </table>
        </div>

        <ul class="divide-y divide-border-subtle md:hidden">
          <li v-for="employee in data.employees" :key="employee.id" class="p-5">
            <div class="flex items-start justify-between gap-3">
              <div class="min-w-0">
                <p class="truncate font-semibold text-ink">{{ employee.fullName }}</p>
                <p class="mt-1 text-xs text-ink-muted">
                  {{ employee.document ? 'DNI ' + employee.document : 'Sin documento' }}
                  <span v-if="employee.employeeNumber"> · {{ employee.employeeNumber }}</span>
                </p>
              </div>
              <span
                :class="employee.active ? 'bg-success-soft text-success-strong' : 'bg-surface-subtle text-ink-muted'"
                class="shrink-0 rounded-full px-2 py-1 text-xs font-semibold"
              >
                {{ employee.active ? 'Activo' : 'Baja' }}
              </span>
            </div>
            <p v-if="employee.phone || employee.email" class="mt-2 text-sm text-ink-muted">{{ employee.phone || employee.email }}</p>
            <div v-if="data.canEdit && employee.active" class="mt-4 flex gap-2">
              <button type="button" :class="secondaryButton" class="flex-1" @click="toggleEdit(employee.id)">Editar</button>
              <button type="button" class="flex-1 rounded-md border border-danger/30 px-3 py-2 text-sm font-semibold text-danger-strong" @click="toggleTerminate(employee.id)">Dar de baja</button>
            </div>

            <div v-if="editingId === employee.id" class="mt-4 rounded-lg border border-border-subtle bg-surface-subtle/50 p-4">
              <form method="post" :action="employee.updateUrl" class="grid gap-3">
                <CsrfInput :csrf="data.csrf" />
                <FormField label="Nombre *" :for-id="`mobile-edit-name-${employee.id}`"><input :id="`mobile-edit-name-${employee.id}`" name="nombre" :value="employee.firstName" required maxlength="100" :class="fieldClass" /></FormField>
                <FormField label="Apellido" :for-id="`mobile-edit-lastname-${employee.id}`"><input :id="`mobile-edit-lastname-${employee.id}`" name="apellido" :value="employee.lastName" maxlength="100" :class="fieldClass" /></FormField>
                <FormField label="Documento" :for-id="`mobile-edit-document-${employee.id}`"><input :id="`mobile-edit-document-${employee.id}`" name="documento" :value="employee.document" maxlength="30" :class="fieldClass" /></FormField>
                <FormField label="Legajo" :for-id="`mobile-edit-number-${employee.id}`"><input :id="`mobile-edit-number-${employee.id}`" name="legajo" :value="employee.employeeNumber" maxlength="50" :class="fieldClass" /></FormField>
                <input type="hidden" name="cuil" :value="employee.cuil || ''" />
                <input type="hidden" name="telefono" :value="employee.phone || ''" />
                <input type="hidden" name="email" :value="employee.email || ''" />
                <input type="hidden" name="fecha_ingreso" :value="employee.hiredAt || ''" />
                <input type="hidden" name="observaciones" :value="employee.notes || ''" />
                <div class="flex justify-end gap-2">
                  <button type="button" :class="secondaryButton" @click="editingId = null">Cancelar</button>
                  <button type="submit" :class="primaryButton">Guardar</button>
                </div>
              </form>
            </div>

            <div v-if="terminatingId === employee.id" class="mt-4 rounded-lg border border-danger/20 bg-danger/5 p-4">
              <form method="post" :action="employee.terminateUrl" class="grid gap-3">
                <CsrfInput :csrf="data.csrf" />
                <FormField label="Fecha de baja" :for-id="`mobile-terminate-date-${employee.id}`"><input :id="`mobile-terminate-date-${employee.id}`" name="fecha_baja" type="date" :class="fieldClass" /></FormField>
                <FormField label="Motivo *" :for-id="`mobile-terminate-reason-${employee.id}`"><input :id="`mobile-terminate-reason-${employee.id}`" name="motivo_baja" required maxlength="500" :class="fieldClass" /></FormField>
                <div class="flex justify-end gap-2">
                  <button type="button" :class="secondaryButton" @click="terminatingId = null">Cancelar</button>
                  <button type="submit" class="rounded-md border border-danger/30 px-4 py-2 text-sm font-semibold text-danger-strong">Confirmar baja</button>
                </div>
              </form>
            </div>
          </li>
        </ul>
      </template>
    </PanelCard>
  </div>
</template>
