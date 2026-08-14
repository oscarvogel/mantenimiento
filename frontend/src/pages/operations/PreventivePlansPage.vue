<script setup>
import { computed, ref, watch } from 'vue'
import { CalendarDaysIcon, PlusIcon, TruckIcon } from '@heroicons/vue/24/outline'
import CsrfInput from './components/CsrfInput.vue'
import EmptyState from './components/EmptyState.vue'
import FormField from './components/FormField.vue'
import PageHeading from './components/PageHeading.vue'
import PaginationBar from './components/PaginationBar.vue'
import PanelCard from './components/PanelCard.vue'
import StatusBadge from './components/StatusBadge.vue'
import EquipmentThumbnail from './components/EquipmentThumbnail.vue'
import { fieldClass, primaryButton, secondaryButton, today } from './helpers.js'

const props = defineProps({ data: { type: Object, required: true } })
const selectedEquipmentId = ref(String(props.data.old?.equipo_id || props.data.wizardEquipmentId || ''))
const selectedServiceId = ref(String(props.data.old?.tipo_servicio_id || ''))
const selectedEquipment = computed(() => props.data.catalogs.equipment.find((item) => String(item.id) === selectedEquipmentId.value) ?? null)
const oldValues = props.data.old ?? {}
const hasOldValues = Object.values(oldValues).some((value) => value !== '' && value !== null && value !== undefined)
const creationMode = ref(props.data.wizardEquipmentId ? 'template' : hasOldValues ? 'manual' : null)
const formValues = ref({
  intervalo_km: oldValues.intervalo_km || '',
  anticipacion_km: oldValues.anticipacion_km || '',
  base_km: oldValues.base_km || '',
  intervalo_horas: oldValues.intervalo_horas || '',
  anticipacion_horas: oldValues.anticipacion_horas || '',
  base_horas: oldValues.base_horas || '',
  intervalo_dias: oldValues.intervalo_dias || '',
  anticipacion_dias: oldValues.anticipacion_dias || '',
  base_fecha: oldValues.base_fecha || '',
  prioridad: oldValues.prioridad || 'MEDIA',
  observaciones: oldValues.observaciones || '',
})
const valueOrBlank = (value) => (value === null || value === undefined ? '' : String(value))
const templateDefaults = computed(() => props.data.catalogs.templateDefaults ?? [])
const suggestionInputs = ref({})
const normalizeText = (value) => String(value ?? '').trim().toLocaleUpperCase('es')
const templateSpecificity = (item) => item.model ? 4 : item.brand && item.equipmentTypeId ? 3 : item.equipmentTypeId ? 2 : 1
const suggestedDefaults = computed(() => {
  const equipment = selectedEquipment.value
  if (!equipment) return []
  const compatible = templateDefaults.value.filter((item) =>
    (!item.equipmentTypeId || Number(item.equipmentTypeId) === Number(equipment.typeId))
    && (!item.brand || normalizeText(item.brand) === normalizeText(equipment.brandName))
    && (!item.model || normalizeText(item.model) === normalizeText(equipment.modelName)),
  ).sort((left, right) => templateSpecificity(right) - templateSpecificity(left) || Number(left.templateId) - Number(right.templateId) || Number(left.id) - Number(right.id))
  const seen = new Set(equipment.assignedServiceTypeIds ?? [])
  return compatible.filter((item) => {
    if (seen.has(Number(item.serviceTypeId))) return false
    seen.add(Number(item.serviceTypeId))
    return true
  })
})
const defaultsForSelectedEquipment = computed(() => {
  return suggestedDefaults.value
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
    base_km: '',
    intervalo_horas: valueOrBlank(templateDefault.intervalHours),
    anticipacion_horas: valueOrBlank(templateDefault.warningHours),
    base_horas: '',
    intervalo_dias: valueOrBlank(templateDefault.intervalDays),
    anticipacion_dias: valueOrBlank(templateDefault.warningDays),
    base_fecha: '',
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

watch(suggestedDefaults, (items) => {
  const next = {}
  for (const item of items) {
    next[item.id] = suggestionInputs.value[item.id] ?? { selected: true, baseKm: '', baseHours: '', baseDate: '' }
  }
  suggestionInputs.value = next
}, { immediate: true })

const addDays = (date, days) => {
  if (!date) return null
  const result = new Date(`${date}T00:00:00`)
  result.setDate(result.getDate() + Number(days))
  return result.toISOString().slice(0, 10)
}
const numeric = (value) => value === '' || value === null || value === undefined ? null : Number(value)
const suggestionPreview = (item) => {
  const input = suggestionInputs.value[item.id] ?? {}
  const nextKm = item.intervalKm && numeric(input.baseKm) !== null ? numeric(input.baseKm) + Number(item.intervalKm) : null
  const nextHours = item.intervalHours && numeric(input.baseHours) !== null ? numeric(input.baseHours) + Number(item.intervalHours) : null
  const nextDate = item.intervalDays && input.baseDate ? addDays(input.baseDate, item.intervalDays) : null
  const missing = (item.intervalKm && (nextKm === null || selectedEquipment.value?.currentKm === null))
    || (item.intervalHours && (nextHours === null || selectedEquipment.value?.currentHours === null))
    || (item.intervalDays && nextDate === null)
  let state = 'SIN_DATOS'
  if (!missing) {
    const overdue = (nextKm !== null && Number(selectedEquipment.value.currentKm) >= nextKm)
      || (nextHours !== null && Number(selectedEquipment.value.currentHours) >= nextHours)
      || (nextDate !== null && today() >= nextDate)
    const due = (nextKm !== null && Number(selectedEquipment.value.currentKm) >= nextKm - Number(item.warningKm ?? 0))
      || (nextHours !== null && Number(selectedEquipment.value.currentHours) >= nextHours - Number(item.warningHours ?? 0))
      || (nextDate !== null && today() >= addDays(nextDate, -Number(item.warningDays ?? 0)))
    state = overdue ? 'VENCIDO' : due ? 'PROXIMO' : 'AL_DIA'
  }
  return { nextKm, nextHours, nextDate, state }
}

const criterionLabel = (key, criterion) => {
  if (key === 'kilometers') return `Cada ${criterion.interval} km · base ${criterion.base ?? 'sin datos'} · próximo ${criterion.next ?? 'sin datos'} km`
  if (key === 'hours') return `Cada ${criterion.interval} h · base ${criterion.base ?? 'sin datos'} · próximo ${criterion.next ?? 'sin datos'} h`
  return `Cada ${criterion.interval} días · base ${criterion.base ?? 'sin datos'} · próximo ${criterion.next ?? 'sin datos'}`
}

const criterionProgress = (key, criterion) => {
  if (key === 'date') return `Hoy: ${criterion.current} · anticipación: ${criterion.warning} días`
  return `Actual: ${criterion.current ?? 'sin datos'} · anticipación: ${criterion.warning} ${key === 'kilometers' ? 'km' : 'h'}`
}

const visibleStateCounts = computed(() => {
  const counts = { AL_DIA: 0, PROXIMO: 0, VENCIDO: 0, SIN_DATOS: 0 }
  for (const plan of props.data.plans.items) {
    if (Object.hasOwn(counts, plan.state)) counts[plan.state] += 1
  }
  return counts
})

const toggleCreationMode = (mode) => {
  creationMode.value = creationMode.value === mode ? null : mode
}
</script>

<template>
  <div>
    <PageHeading eyebrow="Mantenimiento preventivo" title="Planes preventivos" description="Revisá primero el estado de cada unidad y asigná nuevos planes sólo cuando sea necesario.">
      <template #actions>
        <div class="flex flex-wrap gap-2">
          <a :href="data.routes.equipmentIndex" :class="secondaryButton"><TruckIcon class="mr-2 size-5" aria-hidden="true" />Ver equipos</a>
          <button v-if="data.canEdit" type="button" data-testid="open-template-plans" :class="creationMode === 'template' ? primaryButton : secondaryButton" :aria-expanded="creationMode === 'template'" aria-controls="planes-desde-plantilla" @click="toggleCreationMode('template')">Asignar desde plantilla</button>
          <button v-if="data.canEdit" type="button" data-testid="open-manual-plan" :class="creationMode === 'manual' ? primaryButton : secondaryButton" :aria-expanded="creationMode === 'manual'" aria-controls="plan-manual" @click="toggleCreationMode('manual')"><PlusIcon class="mr-2 size-4" aria-hidden="true" />Nuevo manual</button>
        </div>
      </template>
    </PageHeading>

    <div class="flex flex-col">

    <PanelCard v-if="data.canEdit && creationMode === 'template'" id="planes-desde-plantilla" title="Asignar planes desde plantilla" class="order-2 mb-6">
      <form method="post" :action="data.routes.createFromTemplate" class="space-y-5">
        <CsrfInput :csrf="data.csrf" />
        <FormField label="Camión o equipo" for-id="template-equipment" class="max-w-2xl">
          <select id="template-equipment" v-model="selectedEquipmentId" name="equipo_id" required :class="fieldClass">
            <option value="" disabled>Seleccionar equipo</option>
            <option v-for="equipment in data.catalogs.equipment" :key="equipment.id" :value="String(equipment.id)">{{ equipment.code }} · {{ equipment.typeName }} · {{ equipment.branchCode }}</option>
          </select>
        </FormField>

        <p v-if="!selectedEquipment" class="rounded-lg bg-info-subtle p-4 text-sm text-ink">Seleccioná un equipo para detectar automáticamente las plantillas compatibles.</p>
        <p v-else-if="suggestedDefaults.length === 0" class="rounded-lg bg-surface-subtle p-4 text-sm text-ink-muted">No hay servicios nuevos compatibles. Los planes ya asignados no se sugieren nuevamente.</p>
        <template v-else>
          <div class="rounded-lg bg-warning-subtle p-4 text-sm text-ink">
            La lectura actual de <strong>{{ selectedEquipment.code }}</strong> se usa solo para evaluar el estado. Ingresá por separado la última realización conocida de cada servicio; si no la conocés, dejala vacía y el criterio quedará en <strong>SIN_DATOS</strong>.
          </div>
          <div class="space-y-4">
            <article v-for="item in suggestedDefaults" :key="item.id" class="rounded-xl border border-border p-4 sm:p-5">
              <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <label class="flex items-start gap-3">
                  <input v-model="suggestionInputs[item.id].selected" type="checkbox" :name="`planes[${item.id}][seleccionado]`" value="1" class="mt-1 size-4 rounded border-border-strong text-primary focus:ring-primary" />
                  <span><strong class="block text-ink">{{ item.serviceName }}</strong><span class="text-xs text-ink-muted">{{ item.templateName }} · {{ item.model ? 'Modelo' : item.brand ? 'Marca y tipo' : item.equipmentTypeId ? 'Tipo de equipo' : 'Genérica' }}</span></span>
                </label>
                <StatusBadge :status="suggestionPreview(item).state" />
              </div>
              <div class="mt-4 grid gap-3 md:grid-cols-3">
                <div v-if="item.intervalKm" class="rounded-lg bg-surface-subtle p-3">
                  <p class="text-xs font-semibold text-ink-muted">Cada {{ item.intervalKm }} km · anticipación {{ item.warningKm }} km</p>
                  <label class="mt-2 block text-sm font-medium text-ink" :for="`suggestion-km-${item.id}`">Último realizado a los km</label>
                  <input :id="`suggestion-km-${item.id}`" v-model="suggestionInputs[item.id].baseKm" type="number" min="0" :max="selectedEquipment.currentKm ?? undefined" :name="`planes[${item.id}][base_km]`" placeholder="Desconocido" :disabled="!suggestionInputs[item.id].selected" :class="fieldClass" />
                  <p class="mt-2 text-xs text-ink-muted">Próximo: {{ suggestionPreview(item).nextKm === null ? 'sin datos' : `${suggestionPreview(item).nextKm} km` }}</p>
                </div>
                <div v-if="item.intervalHours" class="rounded-lg bg-surface-subtle p-3">
                  <p class="text-xs font-semibold text-ink-muted">Cada {{ item.intervalHours }} h · anticipación {{ item.warningHours }} h</p>
                  <label class="mt-2 block text-sm font-medium text-ink" :for="`suggestion-hours-${item.id}`">Último realizado a las horas</label>
                  <input :id="`suggestion-hours-${item.id}`" v-model="suggestionInputs[item.id].baseHours" type="number" min="0" step="0.1" :max="selectedEquipment.currentHours ?? undefined" :name="`planes[${item.id}][base_horas]`" placeholder="Desconocido" :disabled="!suggestionInputs[item.id].selected" :class="fieldClass" />
                  <p class="mt-2 text-xs text-ink-muted">Próximo: {{ suggestionPreview(item).nextHours === null ? 'sin datos' : `${suggestionPreview(item).nextHours} h` }}</p>
                </div>
                <div v-if="item.intervalDays" class="rounded-lg bg-surface-subtle p-3">
                  <p class="text-xs font-semibold text-ink-muted">Cada {{ item.intervalDays }} días · anticipación {{ item.warningDays }} días</p>
                  <label class="mt-2 block text-sm font-medium text-ink" :for="`suggestion-date-${item.id}`">Último realizado en fecha</label>
                  <input :id="`suggestion-date-${item.id}`" v-model="suggestionInputs[item.id].baseDate" type="date" :max="today()" :name="`planes[${item.id}][base_fecha]`" :disabled="!suggestionInputs[item.id].selected" :class="fieldClass" />
                  <p class="mt-2 text-xs text-ink-muted">Próximo: {{ suggestionPreview(item).nextDate ?? 'sin datos' }}</p>
                </div>
              </div>
              <p v-if="item.notes" class="mt-3 text-sm text-ink-muted">{{ item.notes }}</p>
            </article>
          </div>
          <p class="text-xs text-ink-muted">Podés desmarcar cualquier servicio. La confirmación crea planes y avisos vencidos cuando corresponda, pero nunca genera una orden de trabajo automáticamente.</p>
          <button type="submit" :class="primaryButton" :disabled="!suggestedDefaults.some((item) => suggestionInputs[item.id]?.selected)">Confirmar planes seleccionados</button>
        </template>
      </form>
    </PanelCard>

    <PanelCard v-if="data.canEdit && creationMode === 'manual'" id="plan-manual" title="Crear plan preventivo" class="order-3 mb-6">
        <form method="post" :action="data.routes.create" class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
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
          <p class="rounded-lg bg-warning-subtle p-3 text-sm text-ink md:col-span-2 xl:col-span-4">
            La lectura actual del equipo no se usa como última realización. Informá la base histórica si la conocés; si la dejás vacía, ese criterio quedará en <strong>SIN_DATOS</strong> hasta completar el historial.
          </p>

          <template v-if="!selectedEquipment || selectedEquipment.controlsKm">
            <FormField label="Intervalo (km)" for-id="plan-interval-km"><input id="plan-interval-km" v-model="formValues.intervalo_km" type="number" min="1" name="intervalo_km" :class="fieldClass" /></FormField>
            <FormField label="Anticipación (km)" for-id="plan-warning-km"><input id="plan-warning-km" v-model="formValues.anticipacion_km" type="number" min="0" name="anticipacion_km" :class="fieldClass" /></FormField>
            <FormField label="Último realizado a los km" for-id="plan-base-km" class="md:col-span-2"><input id="plan-base-km" v-model="formValues.base_km" type="number" min="0" name="base_km" placeholder="Dejar vacío si se desconoce" :class="fieldClass" /></FormField>
          </template>
          <template v-if="!selectedEquipment || selectedEquipment.controlsHours">
            <FormField label="Intervalo (horas)" for-id="plan-interval-hours"><input id="plan-interval-hours" v-model="formValues.intervalo_horas" type="number" min="0.1" step="0.1" name="intervalo_horas" :class="fieldClass" /></FormField>
            <FormField label="Anticipación (horas)" for-id="plan-warning-hours"><input id="plan-warning-hours" v-model="formValues.anticipacion_horas" type="number" min="0" step="0.1" name="anticipacion_horas" :class="fieldClass" /></FormField>
            <FormField label="Último realizado a las horas" for-id="plan-base-hours" class="md:col-span-2"><input id="plan-base-hours" v-model="formValues.base_horas" type="number" min="0" step="0.1" name="base_horas" placeholder="Dejar vacío si se desconoce" :class="fieldClass" /></FormField>
          </template>
          <FormField label="Intervalo (días)" for-id="plan-interval-days"><input id="plan-interval-days" v-model="formValues.intervalo_dias" type="number" min="1" name="intervalo_dias" :class="fieldClass" /></FormField>
          <FormField label="Anticipación (días)" for-id="plan-warning-days"><input id="plan-warning-days" v-model="formValues.anticipacion_dias" type="number" min="0" name="anticipacion_dias" :class="fieldClass" /></FormField>
          <FormField label="Último realizado en fecha" for-id="plan-base-date" class="md:col-span-2"><input id="plan-base-date" v-model="formValues.base_fecha" type="date" name="base_fecha" :class="fieldClass" /></FormField>
          <FormField label="Prioridad" for-id="plan-priority"><select id="plan-priority" v-model="formValues.prioridad" name="prioridad" :class="fieldClass"><option v-for="priority in ['BAJA', 'MEDIA', 'ALTA', 'CRITICA']" :key="priority" :value="priority">{{ priority === 'CRITICA' ? 'CRÍTICA' : priority }}</option></select></FormField>
          <FormField label="Observaciones" for-id="plan-notes" class="md:col-span-2 xl:col-span-3"><textarea id="plan-notes" v-model="formValues.observaciones" name="observaciones" maxlength="1000" rows="2" :class="fieldClass"></textarea></FormField>
          <p class="text-xs text-ink-muted md:col-span-2 xl:col-span-4">Completá al menos un intervalo. Si combinás criterios, el plan vence cuando se alcanza primero cualquiera de ellos.</p>
          <button type="submit" :class="`${primaryButton} md:justify-self-start`">Crear plan</button>
        </form>
    </PanelCard>

    <PanelCard title="Planes por equipo" :count="data.plans.total" flush class="order-1 mb-6">
      <form method="get" :action="data.routes.index" class="grid gap-3 border-b border-border-subtle bg-surface-subtle p-5 md:grid-cols-2 xl:grid-cols-5">
        <FormField label="Buscar" for-id="plans-q"><input id="plans-q" name="q" type="search" placeholder="Patente, código o servicio" :value="data.filters.q" :class="fieldClass" /></FormField>
        <FormField label="Camión" for-id="plans-equipment"><select id="plans-equipment" name="equipo_id" :class="fieldClass"><option value="">Todos</option><option v-for="equipment in data.catalogs.equipment" :key="equipment.id" :value="equipment.id" :selected="String(equipment.id) === String(data.filters.equipmentId)">{{ equipment.code }}</option></select></FormField>
        <FormField label="Sucursal" for-id="plans-branch"><select id="plans-branch" name="sucursal_id" :class="fieldClass"><option value="">Todas</option><option v-for="branch in data.catalogs.branches" :key="branch.id" :value="branch.id" :selected="String(branch.id) === String(data.filters.branchId)">{{ branch.code }} · {{ branch.name }}</option></select></FormField>
        <FormField label="Estado" for-id="plans-state"><select id="plans-state" name="estado" :class="fieldClass"><option value="">Todos</option><option v-for="state in ['AL_DIA', 'PROXIMO', 'VENCIDO', 'SIN_DATOS']" :key="state" :value="state" :selected="state === data.filters.state">{{ state.replace('_', ' ') }}</option></select></FormField>
        <div class="flex items-end gap-2"><button type="submit" :class="primaryButton">Filtrar</button><a :href="data.routes.index" :class="secondaryButton">Limpiar</a></div>
      </form>

      <section aria-label="Resumen de planes visibles" class="grid gap-3 border-b border-border-subtle p-5 sm:grid-cols-2 xl:grid-cols-4">
        <article class="rounded-xl border border-border bg-surface-raised p-4"><p class="text-xs font-bold uppercase tracking-wide text-ink-muted">Planes activos</p><p class="mt-2 text-2xl font-bold text-ink">{{ data.plans.total }}</p></article>
        <article class="rounded-xl border border-success/20 bg-success-subtle/40 p-4"><p class="text-xs font-bold uppercase tracking-wide text-success-strong">Al día en esta página</p><p class="mt-2 text-2xl font-bold text-ink">{{ visibleStateCounts.AL_DIA }}</p></article>
        <article class="rounded-xl border border-warning/30 bg-warning-subtle/50 p-4"><p class="text-xs font-bold uppercase tracking-wide text-warning-foreground">Próximos en esta página</p><p class="mt-2 text-2xl font-bold text-ink">{{ visibleStateCounts.PROXIMO }}</p></article>
        <article class="rounded-xl border border-danger/20 bg-danger-subtle/40 p-4"><p class="text-xs font-bold uppercase tracking-wide text-danger-strong">Vencidos en esta página</p><p class="mt-2 text-2xl font-bold text-ink">{{ visibleStateCounts.VENCIDO }}</p></article>
      </section>

      <EmptyState v-if="groupedPlans.length === 0" title="No hay planes preventivos" description="Creá el primer plan o ajustá los filtros de búsqueda." />
      <div v-else class="divide-y divide-border-subtle">
        <section v-for="group in groupedPlans" :key="group.equipment.id" class="p-5 sm:p-6">
          <div class="mb-4 flex flex-col justify-between gap-2 sm:flex-row sm:items-start">
            <div class="flex items-center gap-3"><EquipmentThumbnail :url="group.equipment.photoUrl" :code="group.equipment.code" size="lg" /><div><a v-if="group.equipment.detailUrl" :href="group.equipment.detailUrl" class="text-lg font-bold text-primary hover:text-primary-hover">{{ group.equipment.code }}</a><strong v-else class="text-lg text-ink">{{ group.equipment.code }}</strong><p class="text-sm text-ink-muted">{{ group.equipment.typeName }}<span v-if="group.equipment.plate"> · {{ group.equipment.plate }}</span> · {{ group.branch.code }} / {{ group.branch.name }}</p></div></div>
            <span class="rounded-full bg-surface-muted px-3 py-1 text-xs font-semibold text-ink-muted">{{ group.plans.length }} {{ group.plans.length === 1 ? 'plan' : 'planes' }}</span>
          </div>
          <div class="grid gap-3 xl:grid-cols-2">
            <article v-for="plan in group.plans" :key="plan.id" class="rounded-xl border border-border p-4">
              <div class="flex flex-wrap items-start justify-between gap-2"><div><h3 class="font-semibold text-ink">{{ plan.serviceName }}</h3><p class="mt-1 text-xs text-ink-muted">Prioridad {{ plan.priority }}</p></div><StatusBadge :status="plan.state" /></div>
              <dl class="mt-4 space-y-3 text-sm">
                <template v-for="(criterion, key) in plan.criteria" :key="key"><div v-if="criterion" class="rounded-lg bg-surface-subtle p-3"><dt class="font-semibold capitalize text-ink">{{ key === 'kilometers' ? 'Kilometraje' : key === 'hours' ? 'Horómetro' : 'Fecha' }}</dt><dd class="mt-1 text-ink-muted">{{ criterionLabel(key, criterion) }}</dd><dd class="mt-1 text-xs text-ink-subtle">{{ criterionProgress(key, criterion) }}</dd></div></template>
              </dl>
              <p v-if="plan.notes" class="mt-3 text-sm text-ink-muted">{{ plan.notes }}</p>
              <details v-if="plan.editUrl" class="ui-details-animated mt-4 rounded-xl border border-border bg-surface-subtle p-3 open:bg-white sm:p-4">
                <summary class="cursor-pointer list-none text-sm font-semibold text-primary">Editar plan</summary>
                <form method="post" :action="plan.editUrl" data-confirm data-confirm-title="¿Guardar los cambios?" data-confirm-text="Se actualizará la configuración del plan y se recalcularán sus próximos objetivos." data-confirm-button="Guardar cambios" class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                  <CsrfInput :csrf="data.csrf" />
                  <template v-if="plan.criteria.kilometers">
                    <FormField :label="`Intervalo (km)`" :for-id="`plan-${plan.id}-interval-km`"><input :id="`plan-${plan.id}-interval-km`" name="intervalo_km" type="number" min="1" :value="valueOrBlank(plan.criteria.kilometers.interval)" :class="fieldClass" /></FormField>
                    <FormField label="Anticipación (km)" :for-id="`plan-${plan.id}-warning-km`"><input :id="`plan-${plan.id}-warning-km`" name="anticipacion_km" type="number" min="0" :value="valueOrBlank(plan.criteria.kilometers.warning)" :class="fieldClass" /></FormField>
                    <FormField label="Base (km)" :for-id="`plan-${plan.id}-base-km`"><input :id="`plan-${plan.id}-base-km`" name="base_km" type="number" min="0" :value="valueOrBlank(plan.criteria.kilometers.base)" :class="fieldClass" /></FormField>
                  </template>
                  <template v-if="plan.criteria.hours">
                    <FormField label="Intervalo (horas)" :for-id="`plan-${plan.id}-interval-hours`"><input :id="`plan-${plan.id}-interval-hours`" name="intervalo_horas" type="number" min="0.1" step="0.1" :value="valueOrBlank(plan.criteria.hours.interval)" :class="fieldClass" /></FormField>
                    <FormField label="Anticipación (horas)" :for-id="`plan-${plan.id}-warning-hours`"><input :id="`plan-${plan.id}-warning-hours`" name="anticipacion_horas" type="number" min="0" step="0.1" :value="valueOrBlank(plan.criteria.hours.warning)" :class="fieldClass" /></FormField>
                    <FormField label="Base (horas)" :for-id="`plan-${plan.id}-base-hours`"><input :id="`plan-${plan.id}-base-hours`" name="base_horas" type="number" min="0" step="0.1" :value="valueOrBlank(plan.criteria.hours.base)" :class="fieldClass" /></FormField>
                  </template>
                  <template v-if="plan.criteria.date">
                    <FormField label="Intervalo (días)" :for-id="`plan-${plan.id}-interval-days`"><input :id="`plan-${plan.id}-interval-days`" name="intervalo_dias" type="number" min="1" :value="valueOrBlank(plan.criteria.date.interval)" :class="fieldClass" /></FormField>
                    <FormField label="Anticipación (días)" :for-id="`plan-${plan.id}-warning-days`"><input :id="`plan-${plan.id}-warning-days`" name="anticipacion_dias" type="number" min="0" :value="valueOrBlank(plan.criteria.date.warning)" :class="fieldClass" /></FormField>
                    <FormField label="Base (fecha)" :for-id="`plan-${plan.id}-base-date`"><input :id="`plan-${plan.id}-base-date`" name="base_fecha" type="date" :value="valueOrBlank(plan.criteria.date.base)" :class="fieldClass" /></FormField>
                  </template>
                  <FormField label="Prioridad" :for-id="`plan-${plan.id}-priority`"><select :id="`plan-${plan.id}-priority`" name="prioridad" :class="fieldClass"><option v-for="priority in ['BAJA', 'MEDIA', 'ALTA', 'CRITICA']" :key="priority" :value="priority" :selected="plan.priority === priority">{{ priority === 'CRITICA' ? 'CRÍTICA' : priority }}</option></select></FormField>
                  <FormField label="Observaciones" :for-id="`plan-${plan.id}-notes`" class="md:col-span-2 xl:col-span-3"><textarea :id="`plan-${plan.id}-notes`" name="observaciones" maxlength="1000" rows="2" :value="plan.notes || ''" :class="fieldClass"></textarea></FormField>
                  <p class="text-xs text-ink-muted md:col-span-2 xl:col-span-4">La base y su próximo objetivo se recalculan. Un plan proveniente de plantilla conserva su origen sin modificar la plantilla.</p>
                  <button type="submit" :class="`${primaryButton} md:justify-self-start`">Guardar cambios</button>
                </form>
              </details>
            </article>
          </div>
        </section>
      </div>
      <PaginationBar :pagination="data.plans.pagination" />
    </PanelCard>
    </div>
  </div>
</template>
