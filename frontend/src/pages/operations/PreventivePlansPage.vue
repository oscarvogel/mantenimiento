<script setup>
import { computed, ref } from 'vue'
import { BookOpenIcon, PlusIcon, TruckIcon } from '@heroicons/vue/24/outline'
import CsrfInput from './components/CsrfInput.vue'
import EmptyState from './components/EmptyState.vue'
import FormField from './components/FormField.vue'
import PageHeading from './components/PageHeading.vue'
import PaginationBar from './components/PaginationBar.vue'
import StatusBadge from './components/StatusBadge.vue'
import { fieldClass, primaryButton, secondaryButton, today } from './helpers.js'

const props = defineProps({ data: { type: Object, required: true } })

const showManualForm = ref(false)
const localSearch = ref(props.data.filters?.q ?? '')
const libraryUrl = computed(() => String(props.data.routes.index ?? '').replace(/\/planes(?:\?.*)?$/, '/importaciones/biblioteca'))
const clearUrl = computed(() => props.data.routes.index)
const old = computed(() => props.data.old ?? {})
const selectedManualEquipmentId = ref(String(props.data.old?.equipo_id ?? ''))
const selectedManualEquipment = computed(() => props.data.catalogs.equipment.find((equipment) => String(equipment.id) === selectedManualEquipmentId.value) ?? null)
const manualTemplateDefault = computed(() => {
  const equipment = selectedManualEquipment.value
  if (!equipment) return null
  return (props.data.catalogs.templateDefaults ?? []).find((item) => !item.equipmentTypeId || Number(item.equipmentTypeId) === Number(equipment.typeId)) ?? null
})
const manualValue = (oldKey, templateKey) => {
  const preserved = old.value?.[oldKey]
  if (preserved !== '' && preserved !== null && preserved !== undefined) return preserved
  return manualTemplateDefault.value?.[templateKey] ?? ''
}

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

const criterionText = (key, criterion) => {
  if (!criterion) return null
  if (key === 'kilometers') return `Cada ${criterion.interval} km · próximo ${criterion.next ?? 'sin datos'} km`
  if (key === 'hours') return `Cada ${criterion.interval} h · próximo ${criterion.next ?? 'sin datos'} h`
  return `Cada ${criterion.interval} días · próximo ${criterion.next ?? 'sin datos'}`
}

const planCriteria = (plan) => [
  ['kilometers', plan.criteria.kilometers],
  ['hours', plan.criteria.hours],
  ['date', plan.criteria.date],
].filter(([, criterion]) => criterion)

const equipmentLabel = (equipment) => [equipment.code, equipment.plate, equipment.typeName, equipment.branchCode].filter(Boolean).join(' · ')
</script>

<template>
  <div>
    <PageHeading
      eyebrow="Mantenimiento preventivo"
      title="Planes preventivos"
      description="Administrá los planes ya asignados. Las plantillas, tareas y repuestos se gestionan en la Biblioteca preventiva."
    >
      <template #actions>
        <div class="flex flex-wrap gap-2">
          <a :href="data.routes.equipmentIndex" :class="secondaryButton">
            <TruckIcon class="mr-2 size-5" aria-hidden="true" />Ver equipos
          </a>
          <a :href="libraryUrl" :class="secondaryButton">
            <BookOpenIcon class="mr-2 size-5" aria-hidden="true" />Biblioteca preventiva
          </a>
          <button v-if="data.canEdit" type="button" data-testid="open-manual-plan" :class="showManualForm ? primaryButton : secondaryButton" @click="showManualForm = !showManualForm">
            <PlusIcon class="mr-2 size-4" aria-hidden="true" />Nuevo manual
          </button>
        </div>
      </template>
    </PageHeading>

    <section v-if="data.canEdit && showManualForm" class="mb-6 rounded-2xl border border-border bg-white p-5 shadow-card sm:p-6">
      <div class="mb-5">
        <h2 class="text-base font-bold text-ink">Crear plan manual</h2>
        <p class="mt-1 text-sm text-ink-muted">Usalo sólo cuando el equipo necesite una frecuencia excepcional que no corresponda a una plantilla de la biblioteca. Si existe una referencia compatible, se precarga como punto de partida y podés modificarla.</p>
        <p v-if="manualTemplateDefault" class="mt-2 text-xs font-semibold text-primary">Referencia compatible: {{ manualTemplateDefault.templateName }}</p>
      </div>
      <form method="post" :action="data.routes.create" class="grid gap-4 md:grid-cols-3">
        <CsrfInput :csrf="data.csrf" />
        <FormField label="Equipo" for-id="plan-equipment" class="md:col-span-2">
          <select id="plan-equipment" v-model="selectedManualEquipmentId" name="equipo_id" required :class="fieldClass">
            <option value="" disabled>Seleccionar equipo</option>
            <option v-for="equipment in data.catalogs.equipment" :key="equipment.id" :value="String(equipment.id)">
              {{ equipmentLabel(equipment) }}
            </option>
          </select>
        </FormField>
        <FormField label="Servicio" for-id="plan-service">
          <select id="plan-service" name="tipo_servicio_id" required :class="fieldClass">
            <option value="" disabled :selected="!old.tipo_servicio_id && !manualTemplateDefault">Seleccionar servicio</option>
            <option v-for="service in data.catalogs.serviceTypes" :key="service.id" :value="service.id" :selected="String(old.tipo_servicio_id || manualTemplateDefault?.serviceTypeId || '') === String(service.id)">{{ service.code }} · {{ service.name }}</option>
          </select>
        </FormField>

        <template v-if="selectedManualEquipment?.controlsKm !== false">
          <FormField label="Cada (km)" for-id="manual-interval-km"><input id="manual-interval-km" name="intervalo_km" type="number" min="1" :value="manualValue('intervalo_km', 'intervalKm')" :class="fieldClass" /></FormField>
          <FormField label="Anticipación (km)" for-id="manual-warning-km"><input id="manual-warning-km" name="anticipacion_km" type="number" min="0" :value="manualValue('anticipacion_km', 'warningKm')" :class="fieldClass" /></FormField>
          <FormField label="Base km" for-id="manual-base-km"><input id="manual-base-km" name="base_km" type="number" min="0" :value="old.base_km" :class="fieldClass" /></FormField>
        </template>

        <template v-if="selectedManualEquipment?.controlsHours !== false">
          <FormField label="Cada (horas)" for-id="manual-interval-hours"><input id="manual-interval-hours" name="intervalo_horas" type="number" min="0.1" step="0.1" :value="manualValue('intervalo_horas', 'intervalHours')" :class="fieldClass" /></FormField>
          <FormField label="Anticipación (horas)" for-id="manual-warning-hours"><input id="manual-warning-hours" name="anticipacion_horas" type="number" min="0" step="0.1" :value="manualValue('anticipacion_horas', 'warningHours')" :class="fieldClass" /></FormField>
          <FormField label="Base horas" for-id="manual-base-hours"><input id="manual-base-hours" name="base_horas" type="number" min="0" step="0.1" :value="old.base_horas" :class="fieldClass" /></FormField>
        </template>

        <FormField label="Cada (días)" for-id="manual-interval-days"><input id="manual-interval-days" name="intervalo_dias" type="number" min="1" :value="manualValue('intervalo_dias', 'intervalDays')" :class="fieldClass" /></FormField>
        <FormField label="Anticipación (días)" for-id="manual-warning-days"><input id="manual-warning-days" name="anticipacion_dias" type="number" min="0" :value="manualValue('anticipacion_dias', 'warningDays')" :class="fieldClass" /></FormField>
        <FormField label="Base fecha" for-id="manual-base-date"><input id="manual-base-date" name="base_fecha" type="date" :max="today()" :value="old.base_fecha" :class="fieldClass" /></FormField>

        <FormField label="Prioridad" for-id="manual-priority"><select id="manual-priority" name="prioridad" :class="fieldClass"><option v-for="priority in ['BAJA','MEDIA','ALTA','CRITICA']" :key="priority" :value="priority" :selected="String(old.prioridad || manualTemplateDefault?.priority || 'MEDIA') === priority">{{ priority === 'CRITICA' ? 'CRÍTICA' : priority }}</option></select></FormField>
        <FormField label="Observaciones" for-id="manual-notes" class="md:col-span-2"><input id="manual-notes" name="observaciones" maxlength="1000" :value="old.observaciones || manualTemplateDefault?.notes || ''" :class="fieldClass" /></FormField>
        <div class="md:col-span-3 flex justify-end"><button type="submit" :class="primaryButton">Crear plan manual</button></div>
      </form>
    </section>

    <section class="overflow-hidden rounded-2xl border border-border bg-white shadow-card">
      <div class="border-b border-border bg-surface-subtle p-4 sm:p-5">
        <form method="get" :action="data.routes.index" class="grid gap-3 md:grid-cols-[minmax(15rem,1.3fr)_minmax(14rem,1fr)_minmax(11rem,.8fr)_auto] md:items-end">
          <FormField label="Buscar" for-id="plans-search">
            <input id="plans-search" v-model="localSearch" name="q" type="search" placeholder="Patente, equipo o servicio" :class="fieldClass" />
          </FormField>
          <FormField label="Equipo" for-id="plans-equipment">
            <select id="plans-equipment" name="equipo_id" :class="fieldClass">
              <option value="">Todos</option>
              <option v-for="equipment in data.catalogs.equipment" :key="equipment.id" :value="equipment.id" :selected="String(data.filters.equipmentId) === String(equipment.id)">{{ equipmentLabel(equipment) }}</option>
            </select>
          </FormField>
          <FormField label="Estado" for-id="plans-state">
            <select id="plans-state" name="estado" :class="fieldClass">
              <option value="">Todos</option>
              <option v-for="state in ['AL_DIA','PROXIMO','VENCIDO','SIN_DATOS']" :key="state" :value="state" :selected="data.filters.state === state">{{ state.replace('_', ' ') }}</option>
            </select>
          </FormField>
          <div class="flex gap-2">
            <button type="submit" :class="primaryButton">Filtrar</button>
            <a :href="clearUrl" :class="secondaryButton">Limpiar</a>
          </div>
        </form>
      </div>

      <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border px-4 py-3 sm:px-5">
        <div>
          <h2 class="font-bold text-ink">Planes asignados</h2>
          <p class="mt-0.5 text-xs text-ink-muted">{{ data.plans.total }} plan(es) en total · {{ visiblePlans.length }} visible(s) en esta página</p>
        </div>
        <a :href="libraryUrl" class="text-sm font-semibold text-primary hover:underline">Administrar plantillas y tareas →</a>
      </div>

      <EmptyState v-if="visiblePlans.length === 0" title="No hay planes preventivos" description="Probá limpiando los filtros o asigná un plan desde la ficha del equipo." class="m-5" />

      <div v-else class="overflow-x-auto">
        <table class="ui-table-hover w-full min-w-[66rem] text-left text-sm">
          <thead class="bg-surface-subtle text-xs uppercase tracking-wide text-ink-muted">
            <tr>
              <th class="px-4 py-3">Equipo</th>
              <th class="px-4 py-3">Servicio</th>
              <th class="px-4 py-3">Frecuencia / próximo</th>
              <th class="px-4 py-3">Prioridad</th>
              <th class="px-4 py-3">Estado</th>
              <th class="px-4 py-3 text-right">Acción</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border-subtle">
            <template v-for="plan in visiblePlans" :key="plan.id">
              <tr>
                <td class="px-4 py-3">
                  <a v-if="plan.equipment.detailUrl" :href="plan.equipment.detailUrl" class="font-bold text-primary hover:underline">{{ plan.equipment.code }}</a>
                  <strong v-else class="text-ink">{{ plan.equipment.code }}</strong>
                  <p class="mt-0.5 text-xs text-ink-muted">{{ [plan.equipment.plate, plan.equipment.typeName, plan.branch.code].filter(Boolean).join(' · ') }}</p>
                </td>
                <td class="px-4 py-3"><strong class="text-ink">{{ plan.serviceName }}</strong><p v-if="plan.notes" class="mt-0.5 max-w-xs truncate text-xs text-ink-muted">{{ plan.notes }}</p></td>
                <td class="px-4 py-3"><p v-for="([key, criterion]) in planCriteria(plan)" :key="key" class="text-xs text-ink">{{ criterionText(key, criterion) }}</p><span v-if="planCriteria(plan).length === 0" class="text-xs text-ink-muted">Sin frecuencia</span></td>
                <td class="px-4 py-3 text-xs font-semibold text-ink">{{ plan.priority === 'CRITICA' ? 'CRÍTICA' : plan.priority }}</td>
                <td class="px-4 py-3"><StatusBadge :status="plan.state" /></td>
                <td class="px-4 py-3 text-right"><button v-if="data.canEdit && plan.editUrl" type="button" :class="secondaryButton" :aria-controls="`edit-plan-${plan.id}`" @click="plan._editing = !plan._editing">{{ plan._editing ? 'Cerrar' : 'Editar' }}</button></td>
              </tr>
              <tr v-if="data.canEdit && plan.editUrl" v-show="plan._editing" :id="`edit-plan-${plan.id}`" class="bg-surface-subtle/60">
                <td colspan="6" class="px-4 py-4">
                  <form method="post" :action="plan.editUrl" class="grid gap-3 md:grid-cols-4">
                    <CsrfInput :csrf="data.csrf" />
                    <FormField label="Cada km" :for-id="`edit-km-${plan.id}`"><input :id="`edit-km-${plan.id}`" name="intervalo_km" type="number" min="1" :value="plan.criteria.kilometers?.interval" :class="fieldClass" /></FormField>
                    <FormField label="Anticipación km" :for-id="`edit-wkm-${plan.id}`"><input :id="`edit-wkm-${plan.id}`" name="anticipacion_km" type="number" min="0" :value="plan.criteria.kilometers?.warning" :class="fieldClass" /></FormField>
                    <FormField label="Base km" :for-id="`edit-bkm-${plan.id}`"><input :id="`edit-bkm-${plan.id}`" name="base_km" type="number" min="0" :value="plan.criteria.kilometers?.base" :class="fieldClass" /></FormField>
                    <div></div>
                    <FormField label="Cada horas" :for-id="`edit-hours-${plan.id}`"><input :id="`edit-hours-${plan.id}`" name="intervalo_horas" type="number" min="0.1" step="0.1" :value="plan.criteria.hours?.interval" :class="fieldClass" /></FormField>
                    <FormField label="Anticipación horas" :for-id="`edit-whours-${plan.id}`"><input :id="`edit-whours-${plan.id}`" name="anticipacion_horas" type="number" min="0" step="0.1" :value="plan.criteria.hours?.warning" :class="fieldClass" /></FormField>
                    <FormField label="Base horas" :for-id="`edit-bhours-${plan.id}`"><input :id="`edit-bhours-${plan.id}`" name="base_horas" type="number" min="0" step="0.1" :value="plan.criteria.hours?.base" :class="fieldClass" /></FormField>
                    <div></div>
                    <FormField label="Cada días" :for-id="`edit-days-${plan.id}`"><input :id="`edit-days-${plan.id}`" name="intervalo_dias" type="number" min="1" :value="plan.criteria.date?.interval" :class="fieldClass" /></FormField>
                    <FormField label="Anticipación días" :for-id="`edit-wdays-${plan.id}`"><input :id="`edit-wdays-${plan.id}`" name="anticipacion_dias" type="number" min="0" :value="plan.criteria.date?.warning" :class="fieldClass" /></FormField>
                    <FormField label="Base fecha" :for-id="`edit-bdate-${plan.id}`"><input :id="`edit-bdate-${plan.id}`" name="base_fecha" type="date" :max="today()" :value="plan.criteria.date?.base" :class="fieldClass" /></FormField>
                    <FormField label="Prioridad" :for-id="`edit-priority-${plan.id}`"><select :id="`edit-priority-${plan.id}`" name="prioridad" :class="fieldClass"><option v-for="priority in ['BAJA','MEDIA','ALTA','CRITICA']" :key="priority" :value="priority" :selected="priority === plan.priority">{{ priority === 'CRITICA' ? 'CRÍTICA' : priority }}</option></select></FormField>
                    <FormField label="Observaciones" :for-id="`edit-notes-${plan.id}`" class="md:col-span-3"><input :id="`edit-notes-${plan.id}`" name="observaciones" maxlength="1000" :value="plan.notes" :class="fieldClass" /></FormField>
                    <div class="flex items-end justify-end"><button type="submit" :class="primaryButton">Guardar cambios</button></div>
                  </form>
                </td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>

      <div class="border-t border-border px-4 py-4 sm:px-5"><PaginationBar :pagination="data.plans.pagination" /></div>
    </section>
  </div>
</template>
