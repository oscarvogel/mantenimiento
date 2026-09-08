<script setup>
import { computed } from 'vue'
import { PencilSquareIcon, UserMinusIcon, UserPlusIcon } from '@heroicons/vue/24/outline'
import CsrfInput from './components/CsrfInput.vue'
import EmptyState from './components/EmptyState.vue'
import FormField from './components/FormField.vue'
import PageHeading from './components/PageHeading.vue'
import PanelCard from './components/PanelCard.vue'
import { fieldClass, primaryButton, secondaryButton } from './helpers.js'

const props = defineProps({ data: { type: Object, required: true } })

const activeCount = computed(() => props.data.employees.filter((employee) => employee.active).length)
</script>

<template>
  <div>
    <PageHeading
      eyebrow="Gestión"
      title="Empleados y choferes"
      description="Administrá empleados y choferes de la empresa. Las asignaciones a móviles conservan siempre su historial."
    />

    <PanelCard v-if="data.canEdit" title="Nuevo empleado" class="mb-6">
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
        <div class="lg:col-span-3">
          <button type="submit" :class="primaryButton"><UserPlusIcon class="mr-2 size-5" aria-hidden="true" />Agregar empleado</button>
        </div>
      </form>
    </PanelCard>

    <PanelCard title="Empleados" :count="data.employees.length" class="mb-6">
      <form method="get" :action="data.routes.index" class="mb-5 grid gap-3 md:grid-cols-[1fr_12rem_auto] md:items-end">
        <FormField label="Buscar" for-id="employee-search">
          <input id="employee-search" name="q" :value="data.filters.q" placeholder="Nombre, apellido, documento o legajo" :class="fieldClass" />
        </FormField>
        <FormField label="Estado" for-id="employee-status">
          <select id="employee-status" name="estado" :class="fieldClass">
            <option value="activos" :selected="data.filters.status === 'activos'">Activos</option>
            <option value="baja" :selected="data.filters.status === 'baja'">Dados de baja</option>
            <option value="todos" :selected="data.filters.status === 'todos'">Todos</option>
          </select>
        </FormField>
        <button type="submit" :class="secondaryButton">Filtrar</button>
      </form>

      <div class="mb-4 text-sm text-ink-muted">{{ activeCount }} activos en el resultado actual</div>

      <EmptyState
        v-if="data.employees.length === 0"
        title="No hay empleados para mostrar"
        description="Probá cambiar los filtros o agregá el primer empleado."
      />

      <div v-else class="divide-y divide-border-subtle">
        <article v-for="employee in data.employees" :key="employee.id" class="py-5 first:pt-0 last:pb-0">
          <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
            <div>
              <div class="flex flex-wrap items-center gap-2">
                <h3 class="font-semibold text-ink">{{ employee.fullName }}</h3>
                <span v-if="employee.importedIncomplete" class="rounded-full bg-warning-soft px-2 py-0.5 text-xs font-semibold text-warning-strong">Datos incompletos</span>
                <span :class="employee.active ? 'bg-success-soft text-success-strong' : 'bg-surface-subtle text-ink-muted'" class="rounded-full px-2 py-0.5 text-xs font-semibold">
                  {{ employee.active ? 'Activo' : 'Baja' }}
                </span>
              </div>
              <p class="mt-1 text-sm text-ink-muted">
                {{ employee.document ? 'DNI ' + employee.document : 'Sin documento' }}
                <span v-if="employee.employeeNumber"> · Legajo {{ employee.employeeNumber }}</span>
                <span v-if="employee.cuil"> · CUIL {{ employee.cuil }}</span>
              </p>
              <p v-if="employee.phone || employee.email" class="mt-1 text-sm text-ink-muted">
                {{ employee.phone || '' }}<span v-if="employee.phone && employee.email"> · </span>{{ employee.email || '' }}
              </p>
              <p v-if="!employee.active" class="mt-2 text-sm text-danger-strong">
                Baja {{ employee.terminatedAt || '' }}<span v-if="employee.terminationReason"> · {{ employee.terminationReason }}</span>
              </p>
            </div>
          </div>

          <details v-if="data.canEdit && employee.active" class="mt-4 rounded-lg border border-border-subtle bg-surface-subtle/40">
            <summary class="flex cursor-pointer list-none items-center gap-2 px-4 py-3 text-sm font-semibold text-ink">
              <PencilSquareIcon class="size-4" aria-hidden="true" />Editar datos
            </summary>
            <form method="post" :action="employee.updateUrl" class="grid gap-4 border-t border-border-subtle p-4 lg:grid-cols-3">
              <CsrfInput :csrf="data.csrf" />
              <FormField label="Nombre *"><input name="nombre" :value="employee.firstName" required maxlength="100" :class="fieldClass" /></FormField>
              <FormField label="Apellido"><input name="apellido" :value="employee.lastName" maxlength="100" :class="fieldClass" /></FormField>
              <FormField label="Documento"><input name="documento" :value="employee.document" maxlength="30" :class="fieldClass" /></FormField>
              <FormField label="CUIL"><input name="cuil" :value="employee.cuil" maxlength="30" :class="fieldClass" /></FormField>
              <FormField label="Legajo"><input name="legajo" :value="employee.employeeNumber" maxlength="50" :class="fieldClass" /></FormField>
              <FormField label="Fecha de ingreso"><input name="fecha_ingreso" type="date" :value="employee.hiredAt" :class="fieldClass" /></FormField>
              <FormField label="Teléfono"><input name="telefono" :value="employee.phone" maxlength="50" :class="fieldClass" /></FormField>
              <FormField label="Email"><input name="email" type="email" :value="employee.email" maxlength="150" :class="fieldClass" /></FormField>
              <FormField label="Observaciones" class="lg:col-span-3"><textarea name="observaciones" rows="2" maxlength="1000" :class="fieldClass">{{ employee.notes || '' }}</textarea></FormField>
              <div class="lg:col-span-3"><button type="submit" :class="primaryButton">Guardar cambios</button></div>
            </form>
          </details>

          <details v-if="data.canEdit && employee.active" class="mt-3 rounded-lg border border-danger/20">
            <summary class="flex cursor-pointer list-none items-center gap-2 px-4 py-3 text-sm font-semibold text-danger-strong">
              <UserMinusIcon class="size-4" aria-hidden="true" />Dar de baja
            </summary>
            <form method="post" :action="employee.terminateUrl" class="grid gap-4 border-t border-danger/20 p-4 md:grid-cols-[12rem_1fr_auto] md:items-end">
              <CsrfInput :csrf="data.csrf" />
              <FormField label="Fecha de baja"><input name="fecha_baja" type="date" :class="fieldClass" /></FormField>
              <FormField label="Motivo *"><input name="motivo_baja" required maxlength="500" :class="fieldClass" /></FormField>
              <button type="submit" :class="secondaryButton">Confirmar baja</button>
            </form>
          </details>
        </article>
      </div>
    </PanelCard>
  </div>
</template>
