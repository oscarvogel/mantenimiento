<script setup>
import { computed, ref, watch } from 'vue'
import { CalendarDaysIcon, PlusIcon, TruckIcon } from '@heroicons/vue/24/outline'
import CsrfInput from './components/CsrfInput.vue'
import EmptyState from './components/EmptyState.vue'
import FlashMessages from './components/FlashMessages.vue'
import FormField from './components/FormField.vue'
import PageHeading from './components/PageHeading.vue'
import PaginationBar from './components/PaginationBar.vue'
import PanelCard from './components/PanelCard.vue'
import StatusBadge from './components/StatusBadge.vue'
import { fieldClass, primaryButton, secondaryButton } from './helpers.js'

const props = defineProps({ data: { type: Object, required: true } })
const selectedEquipmentId = ref(String(props.data.old?.equipo_id || ''))
const selectedServiceId = ref(String(props.data.old?.tipo_servicio_id || ''))
const selectedEquipment = computed(() => props.data.catalogs.equipment.find((item) => String(item.id) === selectedEquipmentId.value) ?? null)
const oldValues = props.data.old ?? {}
const hasOldValues = Object.values(oldValues).some((value) => value !== '' && value !== null && value !== undefined)
const formValues = ref({
  intervalo_km: oldValues.intervalo_km || '',
  anticipacion_km: oldValues.anticipacion_km || '',
  intervalo_horas: oldValues.intervalo_horas || '',
  anticipacion_horas: oldValues.anticipacion_horas || '',
  intervalo_dias: oldValues.intervalo_dias || '',
  anticipacion_dias: oldValues.anticipacion_dias || '',
  prioridad: oldValues.prioridad || 'MEDIA',
  observaciones: oldValues.observaciones || '',
})
const valueOrBlank = (value) => (value === null || value === undefined ? '' : String(value))
const templateDefaults = computed(() => props.data.catalogs.templateDefaults ?? [])
const defaultsForSelectedEquipment = computed(() => {
  if (!selectedEquipment.value) return []
  return templateDefaults.value.filter((item) => Number(item.equipmentTypeId) === Number(selectedEquipment.value.typeId))
})
const selectedTemplateDefault = computed(() => defaultsForSelectedEquipment.value.find((item) => String(item.serviceTypeId) === selectedServiceId.value) ?? null)
const groupedPlans = computed(() => {
  const groups = new Map()
  for (const plan of props.data.plans.items) {
    const key = String(plan.equipment.id)
    if (!groups.has(key)) groups.set(key, { equipment: plan.equipment, branch: plan.branch, plans: [] })
    groups.get(key).plans.push(plan)
  }
  return [...groups.values()]
})

const applyTemplateDefault = (templateDefault) => {
  if (!templateDefault) return
  formValues.value = {
    intervalo_km: valueOrBlank(templateDefault.intervalKm),
    anticipacion_km: valueOrBlank(templateDefault.warningKm),
    intervalo_horas: valueOrBlank(templateDefault.intervalHours),
    anticipacion_horas: valueOrBlank(templateDefault.warningHours),
    intervalo_dias: valueOrBlank(templateDefault.intervalDays),
    anticipacion_dias: valueOrBlank(templateDefault.warningDays),
    prioridad: templateDefault.priority || 'MEDIA',
    observaciones: templateDefault.notes || '',
  }
}

watch(selectedEquipmentId, () => {
  const defaults = defaultsForSelectedEquipment.value
  if (defaults.length === 0) return
  if (!defaults.some((item) => String(item.serviceTypeId) === selectedServiceId.value)) {
    selectedServiceId.value = String(defaults[0].serviceTypeId)
    return
  }
  applyTemplateDefault(selectedTemplateDefault.value)
}, { immediate: !hasOldValues })

watch(selectedServiceId, () => applyTemplateDefault(selectedTemplateDefault.value))

const criterionLabel = (key, criterion) => {
  if (key === 'kilometers') return `Cada ${criterion.interval} km · base ${criterion.base} · próximo ${criterion.next} km`
  if (key === 'hours') return `Cada ${criterion.interval} h · base ${criterion.base} · próximo ${criterion.next} h`
  return `Cada ${criterion.interval} días · base ${criterion.base} · próximo ${criterion.next}`
}

const criterionProgress = (key, criterion) => {
  if (key === 'date') return `Hoy: ${criterion.current} · anticipación: ${criterion.warning} días`
  return `Actual: ${criterion.current ?? 'sin datos'} · anticipación: ${criterion.warning} ${key === 'kilometers' ? 'km' : 'h'}`
}
</script>

<template>
  <div>
    <PageHeading eyebrow="Planificación" title="Planes preventivos" description="Definí la frecuencia de servicio y consultá los planes activos de cada camión.">
      <template #actions><a :href="data.routes.equipmentIndex" :class="secondaryButton"><TruckIcon class="mr-2 size-5" aria-hidden="true" />Ver camiones</a></template>
    </PageHeading>
    <FlashMessages :flash="data.flash" />

    <PanelCard v-if="data.canEdit" title="Crear plan preventivo" class="mb-6">
      <details class="rounded-xl border border-border bg-surface-subtle p-4 open:bg-white sm:p-5">
        <summary class="flex cursor-pointer list-none items-center gap-2 font-semibold text-primary"><PlusIcon class="size-5" aria-hidden="true" />Nuevo plan</summary>
        <form method="post" :action="data.routes.create" class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
          <CsrfInput :csrf="data.csrf" />
          <FormField label="Camión o equipo" for-id="plan-equipment" class="md:col-span-2">
            <select id="plan-equipment" v-model="selectedEquipmentId" name="equipo_id" required :class="fieldClass">
              <option value="" disabled>Seleccionar equipo</option>
              <option v-for="equipment in data.catalogs.equipment" :key="equipment.id" :value="String(equipment.id)">{{ equipment.code }} · {{ equipment.typeName }} · {{ equipment.branchCode }}</option>
            </select>
          </FormField>
          <FormField label="Tipo de servicio" for-id="plan-service" class="md:col-span-2">
            <select id="plan-service" v-model="selectedServiceId" name="tipo_servicio_id" required :class="fieldClass"><option value="" disabled>Seleccionar servicio</option><option v-for="service in data.catalogs.serviceTypes" :key="service.id" :value="String(service.id)">{{ service.name }}</option></select>
          </FormField>

          <div v-if="selectedEquipment" class="rounded-lg bg-primary-subtle p-3 text-sm text-ink md:col-span-2 xl:col-span-4">
            <strong>{{ selectedEquipment.code }}</strong> controla
            <span v-if="selectedEquipment.controlsKm"> kilometraje (actual: {{ selectedEquipment.currentKm ?? 'sin datos' }})</span><span v-if="selectedEquipment.controlsKm && selectedEquipment.controlsHours"> y</span><span v-if="selectedEquipment.controlsHours"> horómetro (actual: {{ selectedEquipment.currentHours ?? 'sin datos' }})</span><span v-if="!selectedEquipment.controlsKm && !selectedEquipment.controlsHours"> solamente fecha</span>.
          </div>
          <p v-if="selectedTemplateDefault" class="rounded-lg bg-success-subtle p-3 text-sm font-semibold text-success-strong md:col-span-2 xl:col-span-4">
            {{ selectedTemplateDefault.templateName }} · intervalos precargados
          </p>

          <template v-if="!selectedEquipment || selectedEquipment.controlsKm">
            <FormField label="Intervalo (km)" for-id="plan-interval-km"><input id="plan-interval-km" v-model="formValues.intervalo_km" type="number" min="1" name="intervalo_km" :class="fieldClass" /></FormField>
            <FormField label="Anticipación (km)" for-id="plan-warning-km"><input id="plan-warning-km" v-model="formValues.anticipacion_km" type="number" min="0" name="anticipacion_km" :class="fieldClass" /></FormField>
          </template>
          <template v-if="!selectedEquipment || selectedEquipment.controlsHours">
            <FormField label="Intervalo (horas)" for-id="plan-interval-hours"><input id="plan-interval-hours" v-model="formValues.intervalo_horas" type="number" min="0.1" step="0.1" name="intervalo_horas" :class="fieldClass" /></FormField>
            <FormField label="Anticipación (horas)" for-id="plan-warning-hours"><input id="plan-warning-hours" v-model="formValues.anticipacion_horas" type="number" min="0" step="0.1" name="anticipacion_horas" :class="fieldClass" /></FormField>
          </template>
          <FormField label="Intervalo (días)" for-id="plan-interval-days"><input id="plan-interval-days" v-model="formValues.intervalo_dias" type="number" min="1" name="intervalo_dias" :class="fieldClass" /></FormField>
          <FormField label="Anticipación (días)" for-id="plan-warning-days"><input id="plan-warning-days" v-model="formValues.anticipacion_dias" type="number" min="0" name="anticipacion_dias" :class="fieldClass" /></FormField>
          <FormField label="Prioridad" for-id="plan-priority"><select id="plan-priority" v-model="formValues.prioridad" name="prioridad" :class="fieldClass"><option v-for="priority in ['BAJA', 'MEDIA', 'ALTA', 'CRITICA']" :key="priority" :value="priority">{{ priority === 'CRITICA' ? 'CRÍTICA' : priority }}</option></select></FormField>
          <FormField label="Observaciones" for-id="plan-notes" class="md:col-span-2 xl:col-span-3"><textarea id="plan-notes" v-model="formValues.observaciones" name="observaciones" maxlength="1000" rows="2" :class="fieldClass"></textarea></FormField>
          <p class="text-xs text-ink-muted md:col-span-2 xl:col-span-4">Completá al menos un intervalo. Si combinás criterios, el plan vence cuando se alcanza primero cualquiera de ellos.</p>
          <button type="submit" :class="`${primaryButton} md:justify-self-start`">Crear plan</button>
        </form>
      </details>
    </PanelCard>

    <PanelCard title="Planes asignados por camión" :count="data.plans.total" flush>
      <form method="get" :action="data.routes.index" class="grid gap-3 border-b border-border-subtle bg-surface-subtle p-5 md:grid-cols-2 xl:grid-cols-5">
        <FormField label="Buscar" for-id="plans-q"><input id="plans-q" name="q" type="search" placeholder="Patente, código o servicio" :value="data.filters.q" :class="fieldClass" /></FormField>
        <FormField label="Camión" for-id="plans-equipment"><select id="plans-equipment" name="equipo_id" :class="fieldClass"><option value="">Todos</option><option v-for="equipment in data.catalogs.equipment" :key="equipment.id" :value="equipment.id" :selected="String(equipment.id) === String(data.filters.equipmentId)">{{ equipment.code }}</option></select></FormField>
        <FormField label="Sucursal" for-id="plans-branch"><select id="plans-branch" name="sucursal_id" :class="fieldClass"><option value="">Todas</option><option v-for="branch in data.catalogs.branches" :key="branch.id" :value="branch.id" :selected="String(branch.id) === String(data.filters.branchId)">{{ branch.code }} · {{ branch.name }}</option></select></FormField>
        <FormField label="Estado" for-id="plans-state"><select id="plans-state" name="estado" :class="fieldClass"><option value="">Todos</option><option v-for="state in ['AL_DIA', 'PROXIMO', 'VENCIDO', 'SIN_DATOS']" :key="state" :value="state" :selected="state === data.filters.state">{{ state.replace('_', ' ') }}</option></select></FormField>
        <div class="flex items-end gap-2"><button type="submit" :class="primaryButton">Filtrar</button><a :href="data.routes.index" :class="secondaryButton">Limpiar</a></div>
      </form>

      <EmptyState v-if="groupedPlans.length === 0" title="No hay planes preventivos" description="Creá el primer plan o ajustá los filtros de búsqueda." />
      <div v-else class="divide-y divide-border-subtle">
        <section v-for="group in groupedPlans" :key="group.equipment.id" class="p-5 sm:p-6">
          <div class="mb-4 flex flex-col justify-between gap-2 sm:flex-row sm:items-start">
            <div><a v-if="group.equipment.detailUrl" :href="group.equipment.detailUrl" class="text-lg font-bold text-primary hover:text-primary-hover">{{ group.equipment.code }}</a><strong v-else class="text-lg text-ink">{{ group.equipment.code }}</strong><p class="text-sm text-ink-muted">{{ group.equipment.typeName }}<span v-if="group.equipment.plate"> · {{ group.equipment.plate }}</span> · {{ group.branch.code }} / {{ group.branch.name }}</p></div>
            <span class="rounded-full bg-surface-muted px-3 py-1 text-xs font-semibold text-ink-muted">{{ group.plans.length }} {{ group.plans.length === 1 ? 'plan' : 'planes' }}</span>
          </div>
          <div class="grid gap-3 xl:grid-cols-2">
            <article v-for="plan in group.plans" :key="plan.id" class="rounded-xl border border-border p-4">
              <div class="flex flex-wrap items-start justify-between gap-2"><div><h3 class="font-semibold text-ink">{{ plan.serviceName }}</h3><p class="mt-1 text-xs text-ink-muted">Prioridad {{ plan.priority }}</p></div><StatusBadge :status="plan.state" /></div>
              <dl class="mt-4 space-y-3 text-sm">
                <template v-for="(criterion, key) in plan.criteria" :key="key"><div v-if="criterion" class="rounded-lg bg-surface-subtle p-3"><dt class="font-semibold capitalize text-ink">{{ key === 'kilometers' ? 'Kilometraje' : key === 'hours' ? 'Horómetro' : 'Fecha' }}</dt><dd class="mt-1 text-ink-muted">{{ criterionLabel(key, criterion) }}</dd><dd class="mt-1 text-xs text-ink-subtle">{{ criterionProgress(key, criterion) }}</dd></div></template>
              </dl>
              <p v-if="plan.notes" class="mt-3 text-sm text-ink-muted">{{ plan.notes }}</p>
            </article>
          </div>
        </section>
      </div>
      <PaginationBar :pagination="data.plans.pagination" />
    </PanelCard>
  </div>
</template>
