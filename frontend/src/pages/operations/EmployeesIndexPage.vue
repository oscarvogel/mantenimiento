<script setup>
import { computed, ref } from 'vue'
import { ClockIcon, MagnifyingGlassIcon, PencilSquareIcon, UserMinusIcon, UserPlusIcon } from '@heroicons/vue/24/outline'
import CsrfInput from './components/CsrfInput.vue'
import EmptyState from './components/EmptyState.vue'
import EmployeeExpirationModal from './components/EmployeeExpirationModal.vue'
import EmployeeFormModal from './components/EmployeeFormModal.vue'
import EmployeeTerminationModal from './components/EmployeeTerminationModal.vue'
import FormField from './components/FormField.vue'
import PageHeading from './components/PageHeading.vue'
import PanelCard from './components/PanelCard.vue'
import StatusBadge from './components/StatusBadge.vue'
import { fieldClass, primaryButton, secondaryButton } from './helpers.js'

const props = defineProps({ data: { type: Object, required: true } })

const data = computed(() => ({
  ...props.data,
  employees: (props.data.employees ?? []).map((employee) => ({
    ...employee,
    expirations: employee.expirations ?? [],
  })),
  expirationTypes: props.data.expirationTypes ?? [],
  expirationRoutes: props.data.expirationRoutes ?? { create: '#' },
}))

const formEmployee = ref(undefined)
const terminationEmployee = ref(null)
const expirationEmployee = ref(null)

const activeCount = computed(() => data.value.employees.filter((employee) => employee.active).length)
const selectedHistoryEmployee = computed(() => {
  const id = String(data.value.historyFilters.employeeId || '')
  return data.value.employeeCatalog.find((employee) => String(employee.id) === id) || null
})

const openCreate = () => {
  formEmployee.value = null
  terminationEmployee.value = null
  expirationEmployee.value = null
}

const openEdit = (employee) => {
  formEmployee.value = employee
  terminationEmployee.value = null
  expirationEmployee.value = null
}

const openTerminate = (employee) => {
  terminationEmployee.value = employee
  formEmployee.value = undefined
  expirationEmployee.value = null
}

const openExpiration = (employee) => {
  expirationEmployee.value = employee
  formEmployee.value = undefined
  terminationEmployee.value = null
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
                <th class="px-5 py-3">Vencimientos</th>
                <th class="px-5 py-3 text-right">Acciones</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-border-subtle">
              <tr v-for="employee in data.employees" :key="employee.id" class="hover:bg-brand-50/50">
                <td class="px-5 py-4">
                  <div class="flex items-center gap-3">
                    <div class="flex size-10 shrink-0 items-center justify-center overflow-hidden rounded-full border border-border-subtle bg-surface-subtle text-sm font-bold text-ink-muted">
                      <img v-if="employee.photoUrl" :src="employee.photoUrl" :alt="`Foto de ${employee.fullName}`" class="h-full w-full object-cover" />
                      <span v-else>{{ employee.firstName.slice(0, 1).toUpperCase() }}</span>
                    </div>
                    <div>
                      <div class="font-semibold text-ink">{{ employee.fullName }}</div>
                      <div v-if="employee.importedIncomplete" class="mt-1 text-xs font-medium text-warning-strong">Datos incompletos</div>
                    </div>
                  </div>
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
                  <span v-if="employee.expirations.length === 0" class="text-xs text-ink-muted">Sin vencimientos</span>
                  <div v-else class="space-y-1">
                    <details v-for="expiration in employee.expirations" :key="expiration.id" class="ui-details-animated">
                      <summary class="flex cursor-pointer items-center gap-2">
                        <StatusBadge :status="expiration.status" />
                        <span class="text-xs text-ink">{{ expiration.typeName }} · {{ expiration.expiresAt }}</span>
                      </summary>
                      <form v-if="data.canEdit && employee.active" method="post" :action="expiration.updateUrl" class="mt-2 grid min-w-[18rem] gap-2 rounded-lg border border-border bg-white p-3 shadow-card">
                        <CsrfInput :csrf="data.csrf" />
                        <input type="hidden" name="return_to" value="/mantenimiento/empleados" />
                        <input type="date" name="fecha_emision" :value="expiration.issuedAt" :class="fieldClass" />
                        <input type="date" name="fecha_vencimiento" required :value="expiration.expiresAt" :class="fieldClass" />
                        <input name="numero_documento" maxlength="100" :value="expiration.documentNumber" placeholder="Documento" :class="fieldClass" />
                        <textarea name="observaciones" maxlength="2000" rows="2" :value="expiration.notes" placeholder="Observaciones" :class="fieldClass"></textarea>
                        <div class="flex gap-2">
                          <button type="submit" :class="primaryButton">Guardar</button>
                          <button type="submit" :formaction="expiration.deactivateUrl" class="inline-flex items-center rounded-md border border-danger/30 px-3 py-2 text-sm font-semibold text-danger-strong hover:bg-danger/5">Retirar</button>
                        </div>
                      </form>
                    </details>
                  </div>
                </td>
                <td class="px-5 py-4">
                  <div v-if="data.canEdit && employee.active" class="flex justify-end gap-2">
                    <a :href="employee.historyUrl" :class="secondaryButton">
                      <ClockIcon class="mr-1.5 size-4" aria-hidden="true" />Historial
                    </a>
                    <button type="button" :class="secondaryButton" @click="openExpiration(employee)">
                      Vencimiento
                    </button>
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
              <div class="flex min-w-0 items-center gap-3">
                <div class="flex size-11 shrink-0 items-center justify-center overflow-hidden rounded-full border border-border-subtle bg-surface-subtle text-sm font-bold text-ink-muted">
                  <img v-if="employee.photoUrl" :src="employee.photoUrl" :alt="`Foto de ${employee.fullName}`" class="h-full w-full object-cover" />
                  <span v-else>{{ employee.firstName.slice(0, 1).toUpperCase() }}</span>
                </div>
                <div class="min-w-0">
                  <p class="truncate font-semibold text-ink">{{ employee.fullName }}</p>
                <p class="mt-1 text-xs text-ink-muted">
                  {{ employee.document ? 'DNI ' + employee.document : 'Sin documento' }}
                  <span v-if="employee.employeeNumber"> · {{ employee.employeeNumber }}</span>
                </p>
                </div>
              </div>
              <span
                :class="employee.active ? 'bg-success-soft text-success-strong' : 'bg-surface-subtle text-ink-muted'"
                class="shrink-0 rounded-full px-2 py-1 text-xs font-semibold"
              >
                {{ employee.active ? 'Activo' : 'Baja' }}
              </span>
            </div>
            <p v-if="employee.phone || employee.email" class="mt-2 text-sm text-ink-muted">{{ employee.phone || employee.email }}</p>
            <div v-if="employee.expirations.length" class="mt-3 space-y-1">
              <div v-for="expiration in employee.expirations.slice(0, 2)" :key="expiration.id" class="flex items-center gap-2 text-xs">
                <StatusBadge :status="expiration.status" />
                <span>{{ expiration.typeName }} · {{ expiration.expiresAt }}</span>
              </div>
            </div>
            <button
              v-if="data.canEdit && employee.active"
              type="button"
              :class="`${secondaryButton} mt-4 w-full`"
              @click="openExpiration(employee)"
            >
              Registrar vencimiento
            </button>
            <div class="mt-4 flex gap-2">
              <a :href="employee.historyUrl" :class="secondaryButton" class="flex-1">Historial</a>
              <button v-if="data.canEdit && employee.active" type="button" :class="secondaryButton" class="flex-1" @click="openEdit(employee)">Editar</button>
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


    <PanelCard id="historial-asignaciones" title="Historial de asignaciones" :count="data.assignmentHistory.length" class="mt-6" flush>
      <div class="border-b border-border-subtle p-5">
        <div v-if="selectedHistoryEmployee" class="mb-4 flex flex-wrap items-center gap-2">
          <span class="text-sm font-semibold text-ink">Historial filtrado por:</span>
          <span class="inline-flex items-center gap-2 rounded-full bg-brand-50 px-3 py-1.5 text-sm font-semibold text-brand-700">
            <img v-if="selectedHistoryEmployee.photoUrl" :src="selectedHistoryEmployee.photoUrl" :alt="`Foto de ${selectedHistoryEmployee.name}`" class="size-6 rounded-full object-cover" />
            {{ selectedHistoryEmployee.name }}
          </span>
          <a :href="`${data.routes.index}#historial-asignaciones`" class="text-sm font-semibold text-brand-700 hover:underline">Quitar filtro</a>
        </div>
        <form method="get" :action="data.routes.index" class="grid gap-3 md:grid-cols-2 xl:grid-cols-[minmax(14rem,1fr)_minmax(12rem,1fr)_10rem_10rem_11rem_auto] xl:items-end">
          <FormField label="Chofer" for-id="history-driver">
            <select id="history-driver" name="chofer_id" :class="fieldClass">
              <option value="">Todos</option>
              <option v-for="employee in data.employeeCatalog" :key="employee.id" :value="employee.id" :selected="String(data.historyFilters.employeeId) === String(employee.id)">
                {{ employee.name }}{{ employee.active ? '' : ' · baja' }}
              </option>
            </select>
          </FormField>

          <FormField label="Móvil o patente" for-id="history-equipment">
            <input id="history-equipment" name="movil" :value="data.historyFilters.equipment" placeholder="Código o patente" :class="fieldClass" />
          </FormField>

          <FormField label="Desde" for-id="history-from">
            <input id="history-from" name="desde" type="date" :value="data.historyFilters.from" :class="fieldClass" />
          </FormField>

          <FormField label="Hasta" for-id="history-to">
            <input id="history-to" name="hasta" type="date" :value="data.historyFilters.to" :class="fieldClass" />
          </FormField>

          <FormField label="Vigencia" for-id="history-status">
            <select id="history-status" name="vigencia" :class="fieldClass">
              <option value="todas" :selected="data.historyFilters.status === 'todas'">Todas</option>
              <option value="vigentes" :selected="data.historyFilters.status === 'vigentes'">Vigentes</option>
              <option value="historicas" :selected="data.historyFilters.status === 'historicas'">Históricas</option>
            </select>
          </FormField>

          <div class="flex gap-2">
            <button type="submit" :class="primaryButton">Filtrar</button>
            <a :href="`${data.routes.index}#historial-asignaciones`" :class="secondaryButton">Limpiar</a>
          </div>
        </form>
      </div>

      <EmptyState
        v-if="data.assignmentHistory.length === 0"
        title="No hay asignaciones para estos filtros"
        description="Cambiá los filtros para consultar otras reasignaciones."
      />

      <template v-else>
        <div class="hidden overflow-x-auto md:block">
          <table class="w-full min-w-[62rem] text-left text-sm">
            <thead class="bg-surface-subtle text-xs uppercase tracking-wide text-ink-muted">
              <tr>
                <th class="px-5 py-3">Chofer</th>
                <th class="px-5 py-3">Móvil</th>
                <th class="px-5 py-3">Sucursal</th>
                <th class="px-5 py-3">Desde</th>
                <th class="px-5 py-3">Hasta</th>
                <th class="px-5 py-3">Estado</th>
                <th class="px-5 py-3 text-right">Detalle</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-border-subtle">
              <tr v-for="assignment in data.assignmentHistory" :key="assignment.id" class="hover:bg-brand-50/50">
                <td class="px-5 py-4">
                  <div class="flex items-center gap-3">
                    <img v-if="assignment.employeePhotoUrl" :src="assignment.employeePhotoUrl" :alt="`Foto de ${assignment.employeeName}`" class="size-9 rounded-full border border-border-subtle object-cover" />
                    <div>
                      <div class="font-semibold text-ink">{{ assignment.employeeName }}</div>
                      <div v-if="!assignment.employeeActive" class="mt-1 text-xs text-ink-muted">Empleado dado de baja</div>
                    </div>
                  </div>
                </td>
                <td class="px-5 py-4">
                  <a :href="assignment.equipmentUrl" class="font-semibold text-brand-700 hover:underline">{{ assignment.equipmentCode }}</a>
                  <div class="text-xs text-ink-muted">{{ assignment.equipmentPlate || 'Sin patente' }}</div>
                </td>
                <td class="px-5 py-4 text-ink-muted">{{ assignment.branchName || '—' }}</td>
                <td class="px-5 py-4 text-ink-muted">{{ assignment.startsAt }}</td>
                <td class="px-5 py-4 text-ink-muted">{{ assignment.endsAt || 'Actual' }}</td>
                <td class="px-5 py-4">
                  <span
                    :class="assignment.current ? 'bg-success-soft text-success-strong' : 'bg-surface-subtle text-ink-muted'"
                    class="rounded-full px-2.5 py-1 text-xs font-semibold"
                  >
                    {{ assignment.current ? 'Vigente' : 'Histórica' }}
                  </span>
                </td>
                <td class="px-5 py-4 text-right">
                  <a :href="assignment.equipmentUrl" :class="secondaryButton">Ver móvil</a>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <ul class="divide-y divide-border-subtle md:hidden">
          <li v-for="assignment in data.assignmentHistory" :key="assignment.id" class="p-5">
            <div class="flex items-start justify-between gap-3">
              <div>
                <p class="font-semibold text-ink">{{ assignment.employeeName }}</p>
                <p class="mt-1 text-sm text-ink-muted">
                  {{ assignment.equipmentCode }}<span v-if="assignment.equipmentPlate"> · {{ assignment.equipmentPlate }}</span>
                </p>
              </div>
              <span
                :class="assignment.current ? 'bg-success-soft text-success-strong' : 'bg-surface-subtle text-ink-muted'"
                class="shrink-0 rounded-full px-2 py-1 text-xs font-semibold"
              >
                {{ assignment.current ? 'Vigente' : 'Histórica' }}
              </span>
            </div>
            <p class="mt-2 text-xs text-ink-muted">{{ assignment.startsAt }} → {{ assignment.endsAt || 'Actual' }}</p>
            <a :href="assignment.equipmentUrl" :class="`${secondaryButton} mt-3 w-full`">Ver móvil</a>
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

    <EmployeeExpirationModal
      v-if="expirationEmployee"
      :employee="expirationEmployee"
      :expiration-types="data.expirationTypes"
      :create-url="data.expirationRoutes.create"
      :csrf="data.csrf"
      @close="expirationEmployee = null"
    />
  </div>
</template>
