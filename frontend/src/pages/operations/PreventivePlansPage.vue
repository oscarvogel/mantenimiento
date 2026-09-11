<script setup>
import { computed, ref } from 'vue'
import { TruckIcon, WrenchScrewdriverIcon, XMarkIcon } from '@heroicons/vue/24/outline'
import CsrfInput from './components/CsrfInput.vue'
import EmptyState from './components/EmptyState.vue'
import FormField from './components/FormField.vue'
import PageHeading from './components/PageHeading.vue'
import PaginationBar from './components/PaginationBar.vue'
import StatusBadge from './components/StatusBadge.vue'
import { fieldClass, formatNumberEs, primaryButton, secondaryButton, today } from './helpers.js'

const props = defineProps({ data: { type: Object, required: true } })

const editingPlan = ref(null)
const localSearch = ref(props.data.filters?.q ?? '')
const clearUrl = computed(() => props.data.routes.index)
const servicesUrl = computed(() => String(props.data.routes.index ?? '').replace(/\/planes(?:\?.*)?$/, '/servicios'))
const stateOptions = [
  ['AL_DIA', 'Al día'],
  ['PROXIMO', 'Próximo'],
  ['VENCIDO', 'Vencido'],
  ['SIN_DATOS', 'Sin datos suficientes'],
]

const normalize = (value) => String(value ?? '')
  .normalize('NFD')
  .replace(/[\u0300-\u036f]/g, '')
  .toLocaleLowerCase('es')
  .trim()

const visiblePlans = computed(() => {
  const query = normalize(localSearch.value)
  if (!query) return props.data.plans.items
  return props.data.plans.items.filter((plan) => normalize([
    plan.equipment.code,
    plan.equipment.plate,
    plan.equipment.typeName,
    plan.branch.code,
    plan.branch.name,
    plan.serviceName,
    plan.state,
    plan.priority,
    plan.notes,
  ].filter(Boolean).join(' ')).includes(query))
})

const planCriteria = (plan) => [
  ['kilometers', plan.criteria.kilometers],
  ['hours', plan.criteria.hours],
  ['date', plan.criteria.date],
].filter(([, criterion]) => criterion)

const displayDate = (value) => {
  const match = String(value ?? '').match(/^(\d{4})-(\d{2})-(\d{2})$/)
  return match ? `${match[3]}/${match[2]}/${match[1]}` : 'sin datos'
}

const formatMetric = (value, key) => {
  if (value === null || value === undefined || value === '') return 'sin datos'
  return key === 'hours' ? formatNumberEs(Number(value), 1) : formatNumberEs(Number(value), 0)
}

const differenceText = (key, criterion) => {
  if (criterion.difference === null || criterion.difference === undefined) return 'Faltan datos para calcular'
  const difference = Number(criterion.difference)
  if (key === 'date') {
    if (difference > 0) return `Faltan ${difference} día${difference === 1 ? '' : 's'}`
    if (difference < 0) return `Vencido hace ${Math.abs(difference)} día${Math.abs(difference) === 1 ? '' : 's'}`
    return 'Vence hoy'
  }
  const unit = key === 'hours' ? 'h' : 'km'
  const formatted = formatMetric(Math.abs(difference), key)
  if (difference > 0) return `Faltan ${formatted} ${unit}`
  if (difference < 0) return `Vencido por ${formatted} ${unit}`
  return 'Vence con la lectura actual'
}

const criterionLabel = (key) => key === 'kilometers' ? 'KM' : key === 'hours' ? 'HORAS' : 'FECHA'
const criterionFrequency = (key, criterion) => {
  if (key === 'kilometers') return `Cada ${formatMetric(criterion.interval, key)} km`
  if (key === 'hours') return `Cada ${formatMetric(criterion.interval, key)} h`
  return `Cada ${criterion.interval} días`
}
const criterionCurrent = (key, criterion) => {
  if (key === 'date') return null
  return `Actual: ${formatMetric(criterion.current, key)} ${key === 'hours' ? 'h' : 'km'}`
}
const criterionNext = (key, criterion) => key === 'date'
  ? `Próximo: ${displayDate(criterion.next)}`
  : `Próximo: ${formatMetric(criterion.next, key)} ${key === 'hours' ? 'h' : 'km'}`

const criterionText = (key, criterion) => {
  if (key === 'kilometers') return `Cada ${criterion.interval} km · próximo ${criterion.next ?? 'sin datos'} km`
  if (key === 'hours') return `Cada ${criterion.interval} h · próximo ${criterion.next ?? 'sin datos'} h`
  return `Cada ${criterion.interval} días · próximo ${criterion.next ?? 'sin datos'}`
}

const equipmentLabel = (equipment) => [equipment.code, equipment.plate, equipment.typeName, equipment.branchCode].filter(Boolean).join(' · ')
const openEditModal = (plan) => { editingPlan.value = plan }
const closeEditModal = () => { editingPlan.value = null }
</script>

<template>
  <div>
    <PageHeading
      eyebrow="Mantenimiento preventivo"
      title="Planes preventivos asignados"
      description="Consultá qué mantenimiento tiene asignado cada equipo. La frecuencia se define en el Servicio y acá registrás la última realización."
    >
      <template #actions>
        <div class="flex flex-wrap gap-2">
          <a :href="data.routes.equipmentIndex" :class="primaryButton">
            <TruckIcon class="mr-2 size-5" aria-hidden="true" />Ver equipos
          </a>
          <a :href="servicesUrl" :class="secondaryButton">
            <WrenchScrewdriverIcon class="mr-2 size-5" aria-hidden="true" />Servicios de mantenimiento
          </a>
        </div>
      </template>
    </PageHeading>

    <section class="overflow-hidden rounded-2xl border border-border bg-surface-raised shadow-card">
      <div class="border-b border-border bg-surface-subtle p-4 sm:p-5">
        <form method="get" :action="data.routes.index" class="grid gap-3 md:grid-cols-[minmax(15rem,1.3fr)_minmax(14rem,1fr)_minmax(12rem,.9fr)_minmax(11rem,.8fr)_auto] md:items-end">
          <FormField label="Buscar" for-id="plans-search"><input id="plans-search" v-model="localSearch" name="q" type="search" placeholder="Patente, equipo o servicio" :class="fieldClass" /></FormField>
          <FormField label="Equipo" for-id="plans-equipment">
            <select id="plans-equipment" name="equipo_id" :class="fieldClass">
              <option value="">Todos</option>
              <option v-for="equipment in data.catalogs.equipment" :key="equipment.id" :value="equipment.id" :selected="String(data.filters.equipmentId) === String(equipment.id)">{{ equipmentLabel(equipment) }}</option>
            </select>
          </FormField>
          <FormField label="Sucursal" for-id="plans-branch">
            <select id="plans-branch" name="sucursal_id" :class="fieldClass">
              <option value="">Todas</option>
              <option v-for="branch in data.catalogs.branches" :key="branch.id" :value="branch.id" :selected="String(data.filters.branchId) === String(branch.id)">{{ branch.code }} · {{ branch.name }}</option>
            </select>
          </FormField>
          <FormField label="Estado" for-id="plans-state">
            <select id="plans-state" name="estado" :class="fieldClass">
              <option value="">Todos</option>
              <option v-for="([value, label]) in stateOptions" :key="value" :value="value" :selected="data.filters.state === value">{{ label }}</option>
            </select>
          </FormField>
          <div class="flex gap-2"><button type="submit" :class="primaryButton">Filtrar</button><a :href="clearUrl" :class="secondaryButton">Limpiar</a></div>
        </form>
      </div>

      <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border px-4 py-3 sm:px-5">
        <div>
          <h2 class="font-bold text-ink">Planes asignados</h2>
          <p class="mt-0.5 text-xs text-ink-muted">{{ visiblePlans.length }} {{ visiblePlans.length === 1 ? 'plan visible' : 'planes visibles' }} en esta página · {{ data.plans.total }} en total</p>
        </div>
        <a :href="data.routes.equipmentIndex" class="text-sm font-semibold text-primary hover:underline">Asignar servicios desde Equipos →</a>
      </div>

      <div v-if="visiblePlans.length === 0" class="m-5 rounded-xl border border-border-subtle bg-surface-subtle">
        <EmptyState :title="data.plans.total === 0 ? 'No hay planes asignados' : 'No encontramos planes con esos filtros'" :description="data.plans.total === 0 ? 'Asigná un servicio desde la ficha de un equipo para comenzar.' : 'Probá limpiando los filtros o seleccioná otra sucursal, equipo o estado.'" />
        <div class="flex flex-wrap justify-center gap-2 border-t border-border-subtle px-5 pb-5 pt-4">
          <a :href="data.routes.equipmentIndex" :class="primaryButton">Ver equipos</a>
          <a :href="clearUrl" :class="secondaryButton">Limpiar filtros</a>
        </div>
      </div>

      <div v-else class="overflow-x-auto">
        <table class="ui-table-hover w-full min-w-[72rem] text-left text-sm">
          <thead class="bg-surface-subtle text-xs uppercase tracking-wide text-ink-muted">
            <tr>
              <th class="px-4 py-3">Equipo</th><th class="px-4 py-3">Servicio</th><th class="px-4 py-3">Situación / próximo</th><th class="px-4 py-3">Prioridad</th><th class="px-4 py-3">Estado</th><th class="px-4 py-3 text-right">Acción</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border-subtle">
            <tr v-for="plan in visiblePlans" :key="plan.id">
              <td class="px-4 py-3 align-top">
                <a v-if="plan.equipment.detailUrl" :href="plan.equipment.detailUrl" class="font-bold text-primary hover:underline">{{ plan.equipment.code }}</a>
                <strong v-else class="text-ink">{{ plan.equipment.code }}</strong>
                <p class="mt-0.5 text-xs text-ink-muted">{{ [plan.equipment.plate, plan.equipment.typeName, plan.branch.code].filter(Boolean).join(' · ') }}</p>
              </td>
              <td class="px-4 py-3 align-top"><strong class="text-ink">{{ plan.serviceName }}</strong><p v-if="plan.notes" class="mt-0.5 max-w-xs truncate text-xs text-ink-muted">{{ plan.notes }}</p></td>
              <td class="px-4 py-3 align-top">
                <div v-for="([key, criterion]) in planCriteria(plan)" :key="key" class="mb-3 last:mb-0">
                  <span class="sr-only">{{ criterionText(key, criterion) }}</span>
                  <div class="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs">
                    <strong class="text-ink">{{ criterionLabel(key) }}</strong>
                    <span class="text-ink-muted">{{ criterionFrequency(key, criterion) }}</span>
                  </div>
                  <p v-if="criterionCurrent(key, criterion)" class="mt-0.5 text-xs text-ink-muted">{{ criterionCurrent(key, criterion) }}</p>
                  <p class="text-xs text-ink">{{ criterionNext(key, criterion) }}</p>
                  <p class="mt-0.5 text-xs font-bold" :class="Number(criterion.difference) < 0 ? 'text-danger' : plan.state === 'PROXIMO' ? 'text-warning-strong' : 'text-ink'">{{ differenceText(key, criterion) }}</p>
                </div>
                <span v-if="planCriteria(plan).length === 0" class="text-xs text-ink-muted">Sin frecuencia</span>
              </td>
              <td class="px-4 py-3 align-top text-xs font-semibold text-ink">{{ plan.priority === 'CRITICA' ? 'CRÍTICA' : plan.priority }}</td>
              <td class="px-4 py-3 align-top"><StatusBadge :status="plan.state" /></td>
              <td class="px-4 py-3 align-top">
                <div class="flex flex-col items-stretch gap-2 sm:items-end">
                  <form v-if="plan.generateOrderUrl" method="post" :action="plan.generateOrderUrl">
                    <CsrfInput :csrf="data.csrf" />
                    <button type="submit" :class="primaryButton" :data-testid="`generate-order-${plan.id}`">Generar OT</button>
                  </form>
                  <button v-if="data.canEdit && plan.editUrl" type="button" :class="secondaryButton" :data-testid="`edit-plan-${plan.id}`" @click="openEditModal(plan)">Actualizar última realización</button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

        <div v-if="Number(data.plans.pagination.totalPages) > 1 && visiblePlans.length > 0" class="border-t border-border px-4 py-4 sm:px-5"><PaginationBar :pagination="data.plans.pagination" /></div>
    </section>

    <div v-if="editingPlan" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/55 p-4" role="dialog" aria-modal="true" data-testid="edit-plan-modal" @click.self="closeEditModal">
      <section class="max-h-[92vh] w-full max-w-2xl overflow-y-auto rounded-2xl border border-border bg-surface-raised shadow-2xl">
        <header class="flex items-start justify-between gap-4 border-b border-border bg-surface-raised px-5 py-4 sm:px-6">
          <div>
            <p class="text-xs font-bold uppercase tracking-wide text-primary">Última realización</p>
            <h2 class="mt-1 text-xl font-bold text-ink">{{ editingPlan.serviceName }}</h2>
            <p class="mt-1 text-sm text-ink-muted">{{ editingPlan.equipment.code }} · {{ [editingPlan.equipment.plate, editingPlan.equipment.typeName, editingPlan.branch.code].filter(Boolean).join(' · ') }}</p>
          </div>
          <button type="button" class="rounded-lg p-2 text-ink-muted hover:bg-surface-subtle hover:text-ink" aria-label="Cerrar edición" @click="closeEditModal"><XMarkIcon class="size-5" aria-hidden="true" /></button>
        </header>

        <form method="post" :action="editingPlan.editUrl" class="grid gap-4 p-5 md:grid-cols-2 sm:p-6">
          <CsrfInput :csrf="data.csrf" />

          <div class="md:col-span-2 rounded-xl border border-border bg-surface-subtle p-4">
            <p class="text-sm font-semibold text-ink">Definición del Servicio</p>
            <p v-for="([key, criterion]) in planCriteria(editingPlan)" :key="key" class="mt-1 text-sm text-ink-muted">{{ criterionText(key, criterion) }}</p>
            <p class="mt-2 text-xs text-ink-muted">Para cambiar frecuencia, anticipación o prioridad editá el Servicio de mantenimiento. El cambio se aplicará a todos los equipos que lo usan.</p>
          </div>

          <FormField v-if="editingPlan.criteria.kilometers" label="Último mantenimiento realizado a (km)" :for-id="`edit-bkm-${editingPlan.id}`"><input :id="`edit-bkm-${editingPlan.id}`" name="base_km" type="number" min="0" :value="editingPlan.criteria.kilometers.base" :class="fieldClass" /></FormField>
          <FormField v-if="editingPlan.criteria.hours" label="Último mantenimiento realizado a (h)" :for-id="`edit-bhours-${editingPlan.id}`"><input :id="`edit-bhours-${editingPlan.id}`" name="base_horas" type="number" min="0" step="0.1" :value="editingPlan.criteria.hours.base" :class="fieldClass" /></FormField>
          <FormField v-if="editingPlan.criteria.date" label="Último mantenimiento realizado el" :for-id="`edit-bdate-${editingPlan.id}`"><input :id="`edit-bdate-${editingPlan.id}`" name="base_fecha" type="date" :max="today()" :value="editingPlan.criteria.date.base" :class="fieldClass" /></FormField>
          <FormField label="Observaciones" :for-id="`edit-notes-${editingPlan.id}`" class="md:col-span-2"><input :id="`edit-notes-${editingPlan.id}`" name="observaciones" maxlength="1000" :value="editingPlan.notes" :class="fieldClass" /></FormField>

          <div class="md:col-span-2 flex flex-wrap justify-end gap-2 border-t border-border pt-4">
            <button type="button" :class="secondaryButton" @click="closeEditModal">Cancelar</button>
            <button type="submit" :class="primaryButton">Guardar última realización</button>
          </div>
        </form>
      </section>
    </div>
  </div>
</template>
