<script setup>
import { computed } from 'vue'
import {
  ArrowTopRightOnSquareIcon,
  CalendarDaysIcon,
  ExclamationTriangleIcon,
  MagnifyingGlassIcon,
} from '@heroicons/vue/24/outline'
import EmptyState from './components/EmptyState.vue'
import FormField from './components/FormField.vue'
import PageHeading from './components/PageHeading.vue'
import PanelCard from './components/PanelCard.vue'
import StatusBadge from './components/StatusBadge.vue'
import { fieldClass, primaryButton, secondaryButton } from './helpers.js'

const props = defineProps({ data: { type: Object, required: true } })
const data = computed(() => ({
  ...props.data,
  items: props.data.items ?? [],
  branches: props.data.branches ?? [],
  summary: props.data.summary ?? { total: 0, overdue: 0, next7: 0, next30: 0 },
  filters: props.data.filters ?? { subject: 'TODOS', status: 'todos', branchId: '', q: '' },
}))

const daysLabel = (days) => {
  const value = Number(days)
  if (value < 0) return `Venció hace ${Math.abs(value)} día${Math.abs(value) === 1 ? '' : 's'}`
  if (value === 0) return 'Vence hoy'
  return `Vence en ${value} día${value === 1 ? '' : 's'}`
}

const statusFor = (item) => item.status || (Number(item.daysUntil) < 0 ? 'VENCIDO' : 'AL_DIA')
</script>

<template>
  <div>
    <PageHeading
      eyebrow="Gestión"
      title="Próximos vencimientos"
      description="Controlá en una sola pantalla la documentación y vencimientos de equipos, camiones, empleados y choferes."
    >
      <template #actions>
        <a v-if="data.canManageTypes" :href="data.routes.types" :class="secondaryButton">
          <CalendarDaysIcon class="mr-2 size-5" aria-hidden="true" />
          Tipos de vencimiento
        </a>
      </template>
    </PageHeading>

    <section class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4" aria-label="Resumen de vencimientos">
      <div class="rounded-xl border border-border bg-surface-raised p-5">
        <p class="text-xs font-semibold uppercase tracking-wide text-ink-muted">Mostrados</p>
        <p class="mt-2 text-3xl font-bold text-ink">{{ data.summary.total }}</p>
      </div>
      <div class="rounded-xl border border-danger/20 bg-danger-subtle p-5">
        <p class="text-xs font-semibold uppercase tracking-wide text-danger-strong">Vencidos</p>
        <p class="mt-2 text-3xl font-bold text-danger-strong">{{ data.summary.overdue }}</p>
      </div>
      <div class="rounded-xl border border-warning/25 bg-warning-subtle p-5">
        <p class="text-xs font-semibold uppercase tracking-wide text-warning-foreground">Próximos 7 días</p>
        <p class="mt-2 text-3xl font-bold text-warning-foreground">{{ data.summary.next7 }}</p>
      </div>
      <div class="rounded-xl border border-border bg-surface-raised p-5">
        <p class="text-xs font-semibold uppercase tracking-wide text-ink-muted">Próximos 30 días</p>
        <p class="mt-2 text-3xl font-bold text-ink">{{ data.summary.next30 }}</p>
      </div>
    </section>

    <PanelCard title="Filtrar vencimientos" class="mb-6">
      <form method="get" :action="data.routes.index" class="grid gap-3 lg:grid-cols-[minmax(15rem,1fr)_11rem_11rem_13rem_auto] lg:items-end">
        <FormField label="Equipo, patente, empleado o tipo" for-id="expiration-search">
          <div class="relative">
            <MagnifyingGlassIcon class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-ink-muted" aria-hidden="true" />
            <input
              id="expiration-search"
              name="q"
              :value="data.filters.q"
              placeholder="Ej.: BEN4G47, seguro o Ariel"
              :class="`${fieldClass} pl-9`"
            />
          </div>
        </FormField>

        <FormField label="Tipo" for-id="expiration-subject">
          <select id="expiration-subject" name="tipo" :class="fieldClass">
            <option v-if="data.canSeeEquipment && data.canSeeEmployees" value="TODOS" :selected="data.filters.subject === 'TODOS'">Todos</option>
            <option v-if="data.canSeeEquipment" value="EQUIPO" :selected="data.filters.subject === 'EQUIPO'">Equipos</option>
            <option v-if="data.canSeeEmployees" value="EMPLEADO" :selected="data.filters.subject === 'EMPLEADO'">Empleados</option>
          </select>
        </FormField>

        <FormField label="Estado" for-id="expiration-status">
          <select id="expiration-status" name="estado" :class="fieldClass">
            <option value="todos" :selected="data.filters.status === 'todos'">Todos</option>
            <option value="vencidos" :selected="data.filters.status === 'vencidos'">Vencidos</option>
            <option value="7" :selected="data.filters.status === '7'">Próximos 7 días</option>
            <option value="15" :selected="data.filters.status === '15'">Próximos 15 días</option>
            <option value="30" :selected="data.filters.status === '30'">Próximos 30 días</option>
            <option value="vigentes" :selected="data.filters.status === 'vigentes'">Más de 30 días</option>
          </select>
        </FormField>

        <FormField label="Sucursal" for-id="expiration-branch">
          <select id="expiration-branch" name="sucursal_id" :class="fieldClass">
            <option value="">Todas</option>
            <option
              v-for="branch in data.branches"
              :key="branch.id"
              :value="branch.id"
              :selected="String(data.filters.branchId) === String(branch.id)"
            >
              {{ branch.name }}
            </option>
          </select>
        </FormField>

        <button type="submit" :class="primaryButton">Buscar</button>
      </form>
    </PanelCard>

    <PanelCard title="Vencimientos" :count="data.items.length" flush>
      <EmptyState
        v-if="data.items.length === 0"
        title="No hay vencimientos para estos filtros"
        description="Probá cambiando el período, el tipo de sujeto o la sucursal."
      />

      <template v-else>
        <div class="hidden overflow-x-auto md:block">
          <table class="w-full min-w-[64rem] text-left text-sm">
            <thead class="bg-surface-subtle text-xs uppercase tracking-wide text-ink-muted">
              <tr>
                <th class="px-5 py-3">Sujeto</th>
                <th class="px-5 py-3">Vencimiento</th>
                <th class="px-5 py-3">Fecha</th>
                <th class="px-5 py-3">Situación</th>
                <th class="px-5 py-3">Sucursal</th>
                <th class="px-5 py-3">Documento</th>
                <th class="px-5 py-3 text-right">Acción</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-border-subtle">
              <tr v-for="item in data.items" :key="item.id" class="hover:bg-brand-50/50">
                <td class="px-5 py-4">
                  <div class="font-semibold text-ink">{{ item.subjectName }}</div>
                  <div class="mt-1 text-xs text-ink-muted">{{ item.subjectType === 'EQUIPO' ? 'Equipo / móvil' : 'Empleado / chofer' }}</div>
                </td>
                <td class="px-5 py-4">
                  <div class="font-medium text-ink">{{ item.typeName }}</div>
                  <div v-if="item.notes" class="mt-1 max-w-xs truncate text-xs text-ink-muted">{{ item.notes }}</div>
                </td>
                <td class="px-5 py-4 font-medium text-ink">{{ item.expiresAt }}</td>
                <td class="px-5 py-4">
                  <div class="flex flex-col items-start gap-1.5">
                    <StatusBadge :status="statusFor(item)" />
                    <span
                      class="text-xs font-medium"
                      :class="Number(item.daysUntil) < 0 ? 'text-danger-strong' : Number(item.daysUntil) <= 7 ? 'text-warning-foreground' : 'text-ink-muted'"
                    >
                      {{ daysLabel(item.daysUntil) }}
                    </span>
                  </div>
                </td>
                <td class="px-5 py-4 text-ink-muted">{{ item.branchName || '—' }}</td>
                <td class="px-5 py-4 text-ink-muted">{{ item.documentNumber || '—' }}</td>
                <td class="px-5 py-4 text-right">
                  <a :href="item.subjectUrl" :class="secondaryButton" class="whitespace-nowrap">
                    Gestionar
                    <ArrowTopRightOnSquareIcon class="ml-2 size-4" aria-hidden="true" />
                  </a>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="divide-y divide-border-subtle md:hidden">
          <article v-for="item in data.items" :key="item.id" class="space-y-3 p-4">
            <div class="flex items-start justify-between gap-3">
              <div>
                <p class="font-semibold text-ink">{{ item.subjectName }}</p>
                <p class="mt-1 text-sm text-ink-muted">{{ item.typeName }}</p>
              </div>
              <StatusBadge :status="statusFor(item)" />
            </div>
            <div class="flex items-center gap-2 text-sm">
              <ExclamationTriangleIcon v-if="Number(item.daysUntil) < 0" class="size-4 text-danger" aria-hidden="true" />
              <CalendarDaysIcon v-else class="size-4 text-ink-muted" aria-hidden="true" />
              <span :class="Number(item.daysUntil) < 0 ? 'font-semibold text-danger-strong' : 'text-ink-muted'">
                {{ item.expiresAt }} · {{ daysLabel(item.daysUntil) }}
              </span>
            </div>
            <p v-if="item.branchName" class="text-xs text-ink-muted">Sucursal: {{ item.branchName }}</p>
            <a :href="item.subjectUrl" :class="secondaryButton" class="w-full justify-center">
              Gestionar
              <ArrowTopRightOnSquareIcon class="ml-2 size-4" aria-hidden="true" />
            </a>
          </article>
        </div>
      </template>
    </PanelCard>
  </div>
</template>
