<script setup>
import { computed, ref } from 'vue'
import { MagnifyingGlassIcon, PencilSquareIcon, UserMinusIcon, UserPlusIcon } from '@heroicons/vue/24/outline'
import EmptyState from './components/EmptyState.vue'
import EmployeeFormModal from './components/EmployeeFormModal.vue'
import EmployeeTerminationModal from './components/EmployeeTerminationModal.vue'
import FormField from './components/FormField.vue'
import PageHeading from './components/PageHeading.vue'
import PanelCard from './components/PanelCard.vue'
import { fieldClass, primaryButton, secondaryButton } from './helpers.js'

const props = defineProps({ data: { type: Object, required: true } })

const formEmployee = ref(undefined)
const terminationEmployee = ref(null)

const activeCount = computed(() => props.data.employees.filter((employee) => employee.active).length)

const openCreate = () => {
  formEmployee.value = null
  terminationEmployee.value = null
}

const openEdit = (employee) => {
  formEmployee.value = employee
  terminationEmployee.value = null
}

const openTerminate = (employee) => {
  terminationEmployee.value = employee
  formEmployee.value = undefined
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

        <button v-if="data.canEdit" type="button" :class="secondaryButton" class="shrink-0" @click="openCreate">
          <UserPlusIcon class="mr-2 size-5" aria-hidden="true" />
          Nuevo empleado
        </button>
      </div>

      <p class="mt-3 text-sm text-ink-muted">
        {{ data.employees.length }} resultado{{ data.employees.length === 1 ? '' : 's' }} · {{ activeCount }} activo{{ activeCount === 1 ? '' : 's' }}
      </p>
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
              <tr v-for="employee in data.employees" :key="employee.id" class="hover:bg-brand-50/50">
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
                    <button type="button" :class="secondaryButton" @click="openEdit(employee)">
                      <PencilSquareIcon class="mr-1.5 size-4" aria-hidden="true" />Editar
                    </button>
                    <button
                      type="button"
                      class="inline-flex items-center rounded-md border border-danger/30 px-3 py-2 text-sm font-semibold text-danger-strong hover:bg-danger/5"
                      @click="openTerminate(employee)"
                    >
                      <UserMinusIcon class="mr-1.5 size-4" aria-hidden="true" />Baja
                    </button>
                  </div>
                  <div v-else class="text-right text-xs text-ink-muted">Sin acciones</div>
                </td>
              </tr>
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
              <button type="button" :class="secondaryButton" class="flex-1" @click="openEdit(employee)">Editar</button>
              <button
                type="button"
                class="flex-1 rounded-md border border-danger/30 px-3 py-2 text-sm font-semibold text-danger-strong"
                @click="openTerminate(employee)"
              >
                Dar de baja
              </button>
            </div>
          </li>
        </ul>
      </template>
    </PanelCard>

    <EmployeeFormModal
      v-if="formEmployee !== undefined"
      :employee="formEmployee"
      :create-url="data.routes.create"
      :csrf="data.csrf"
      @close="formEmployee = undefined"
    />

    <EmployeeTerminationModal
      v-if="terminationEmployee"
      :employee="terminationEmployee"
      :csrf="data.csrf"
      @close="terminationEmployee = null"
    />
  </div>
</template>
