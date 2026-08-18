<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { BookOpenIcon, PlusIcon, TruckIcon, XMarkIcon } from '@heroicons/vue/24/outline'
import CsrfInput from './components/CsrfInput.vue'
import EmptyState from './components/EmptyState.vue'
import FormField from './components/FormField.vue'
import PageHeading from './components/PageHeading.vue'
import PaginationBar from './components/PaginationBar.vue'
import StatusBadge from './components/StatusBadge.vue'
import { fieldClass, formatNumberEs, primaryButton, secondaryButton, today } from './helpers.js'

const props = defineProps({ data: { type: Object, required: true } })

const showManualForm = ref(false)
const editingPlan = ref(null)
const localSearch = ref(props.data.filters?.q ?? '')
const libraryUrl = computed(() => String(props.data.routes.index ?? '').replace(/\/planes(?:\?.*)?$/, '/importaciones/biblioteca'))
const clearUrl = computed(() => props.data.routes.index)
const old = computed(() => props.data.old ?? {})
const selectedManualEquipmentId = ref(String(props.data.old?.equipo_id ?? ''))
const selectedManualServiceId = ref(String(props.data.old?.tipo_servicio_id ?? ''))
const selectedManualEquipment = computed(() => props.data.catalogs.equipment.find((equipment) => String(equipment.id) === selectedManualEquipmentId.value) ?? null)
const editingEquipment = computed(() => {
  if (!editingPlan.value) return null
  return props.data.catalogs.equipment.find((equipment) => Number(equipment.id) === Number(editingPlan.value.equipment.id)) ?? null
})
const manualTemplateDefault = computed(() => {
  const equipment = selectedManualEquipment.value
  if (!equipment || !selectedManualServiceId.value) return null
  const sameText = (left, right) => !left || normalize(left) === normalize(right)
  return (props.data.catalogs.templateDefaults ?? []).find((item) => (
    (item.equipmentTypeId === null || item.equipmentTypeId === undefined || Number(item.equipmentTypeId) === Number(equipment.typeId))
    && Number(item.serviceTypeId) === Number(selectedManualServiceId.value)
    && sameText(item.brand, equipment.brandName)
    && sameText(item.model, equipment.modelName)
  )) ?? null
})

const normalize = (value) => String(value ?? '')
  .normalize('NFD')
  .replace(/[\u0300-\u036f]/g, '')
  .toLocaleLowerCase('es')
  .trim()

const manualSupportsKm = computed(() => selectedManualEquipment.value?.controlsKm === true)
const manualSupportsHours = computed(() => selectedManualEquipment.value?.controlsHours === true)
const manualDraft = reactive({
  intervalo_km: '', anticipacion_km: '', base_km: '', intervalo_horas: '', anticipacion_horas: '', base_horas: '',
  intervalo_dias: '', anticipacion_dias: '', base_fecha: '',
})
const preservedOr = (key, fallback) => {
  const value = old.value?.[key]
  return value !== '' && value !== null && value !== undefined ? value : (fallback ?? '')
}
const syncManualDraft = () => {
  const template = manualTemplateDefault.value
  manualDraft.intervalo_km = preservedOr('intervalo_km', template?.intervalKm)
  manualDraft.anticipacion_km = preservedOr('anticipacion_km', template?.warningKm)
  manualDraft.base_km = preservedOr('base_km', selectedManualEquipment.value?.currentKm)
  manualDraft.intervalo_horas = preservedOr('intervalo_horas', template?.intervalHours)
  manualDraft.anticipacion_horas = preservedOr('anticipacion_horas', template?.warningHours)
  manualDraft.base_horas = preservedOr('base_horas', selectedManualEquipment.value?.currentHours)
  manualDraft.intervalo_dias = preservedOr('intervalo_dias', template?.intervalDays)
  manualDraft.anticipacion_dias = preservedOr('anticipacion_dias', template?.warningDays)
  manualDraft.base_fecha = preservedOr('base_fecha', today())
}
watch([selectedManualEquipmentId, selectedManualServiceId, manualTemplateDefault], syncManualDraft, { immediate: true })
const numberValue = (value) => {
  if (value === '' || value === null || value === undefined) return null
  const parsed = Number(String(value).replace(',', '.'))
  return Number.isFinite(parsed) && parsed >= 0 ? parsed : null
}
const localDate = (value) => {
  const match = String(value ?? '').match(/^(\d{4})-(\d{2})-(\d{2})$/)
  return match ? new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3])) : null
}
const dateValue = (date) => {
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}
const displayDate = (value) => {
  const date = localDate(value)
  if (!date) return 'sin datos'
  return `${String(date.getDate()).padStart(2, '0')}/${String(date.getMonth() + 1).padStart(2, '0')}/${date.getFullYear()}`
}
const manualPreview = computed(() => {
  const equipment = selectedManualEquipment.value
  if (!equipment) return null
  const preview = { kilometers: null, hours: null, date: null }
  if (manualSupportsKm.value) {
    const base = numberValue(manualDraft.base_km)
    const interval = numberValue(manualDraft.intervalo_km)
    if (base !== null && interval !== null) {
      const warning = numberValue(manualDraft.anticipacion_km) ?? 0
      preview.kilometers = { base, interval, next: base + interval, warningFrom: base + interval - warning }
    }
  }
  if (manualSupportsHours.value) {
    const base = numberValue(manualDraft.base_horas)
    const interval = numberValue(manualDraft.intervalo_horas)
    if (base !== null && interval !== null) {
      const warning = numberValue(manualDraft.anticipacion_horas) ?? 0
      preview.hours = { base, interval, next: base + interval, warningFrom: base + interval - warning }
    }
  }
  const baseDate = localDate(manualDraft.base_fecha)
  const intervalDays = numberValue(manualDraft.intervalo_dias)
  if (baseDate && intervalDays !== null) {
    const next = new Date(baseDate.getFullYear(), baseDate.getMonth(), baseDate.getDate() + intervalDays)
    const warningDays = numberValue(manualDraft.anticipacion_dias) ?? 0
    const warningFrom = new Date(next.getFullYear(), next.getMonth(), next.getDate() - warningDays)
    preview.date = { base: dateValue(baseDate), interval: intervalDays, next: dateValue(next), warningFrom: dateValue(warningFrom) }
  }
  return preview
})

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
  if (key === 'kilometers') return `Frecuencia ${criterion.interval} km · próximo ${criterion.next ?? 'sin datos'} km`
  if (key === 'hours') return `Frecuencia ${criterion.interval} h · próximo ${criterion.next ?? 'sin datos'} h`
  return `Frecuencia ${criterion.interval} días · próximo ${criterion.next ?? 'sin datos'}`
}

const planCriteria = (plan) => [
  ['kilometers', plan.criteria.kilometers],
  ['hours', plan.criteria.hours],
  ['date', plan.criteria.date],
].filter(([, criterion]) => criterion)

const equipmentLabel = (equipment) => [equipment.code, equipment.plate, equipment.typeName, equipment.branchCode].filter(Boolean).join(' · ')
const openEditModal = (plan) => { editingPlan.value = plan }
const closeEditModal = () => { editingPlan.value = null }
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
        <p v-if="manualTemplateDefault" class="mt-2 rounded-lg bg-primary-subtle px-3 py-2 text-xs text-ink">Referencia aplicada al servicio seleccionado. Podés ajustar los valores antes de guardar.</p>
      </div>
      <form method="post" :action="data.routes.create" class="grid gap-4 md:grid-cols-3">
        <CsrfInput :csrf="data.csrf" />
        <FormField label="Equipo" for-id="plan-equipment" class="md:col-span-2">
          <select id="plan-equipment" v-model="selectedManualEquipmentId" name="equipo_id" required :class="fieldClass">
            <option value="" disabled>Seleccionar equipo</option>
            <option v-for="equipment in data.catalogs.equipment" :key="equipment.id" :value="String(equipment.id)">{{ equipmentLabel(equipment) }}</option>
          </select>
        </FormField>
        <FormField label="Servicio" for-id="plan-service">
          <select id="plan-service" v-model="selectedManualServiceId" name="tipo_servicio_id" required :class="fieldClass">
            <option value="" disabled>Seleccionar servicio</option>
            <option v-for="service in data.catalogs.serviceTypes" :key="service.id" :value="String(service.id)">{{ service.code }} · {{ service.name }}</option>
          </select>
        </FormField>

        <template v-if="manualSupportsKm">
          <FormField label="Frecuencia (kilómetros)" for-id="manual-interval-km"><input id="manual-interval-km" v-model="manualDraft.intervalo_km" name="intervalo_km" type="number" min="1" :class="fieldClass" /></FormField>
          <FormField label="Avisar antes (kilómetros)" for-id="manual-warning-km"><input id="manual-warning-km" v-model="manualDraft.anticipacion_km" name="anticipacion_km" type="number" min="0" :class="fieldClass" /></FormField>
          <FormField label="Último mantenimiento realizado a (km)" for-id="manual-base-km"><input id="manual-base-km" v-model="manualDraft.base_km" name="base_km" type="number" min="0" :class="fieldClass" /></FormField>
        </template>

        <template v-if="manualSupportsHours">
          <FormField label="Frecuencia (horas)" for-id="manual-interval-hours"><input id="manual-interval-hours" v-model="manualDraft.intervalo_horas" name="intervalo_horas" type="number" min="0.1" step="0.1" :class="fieldClass" /></FormField>
          <FormField label="Avisar antes (horas)" for-id="manual-warning-hours"><input id="manual-warning-hours" v-model="manualDraft.anticipacion_horas" name="anticipacion_horas" type="number" min="0" step="0.1" :class="fieldClass" /></FormField>
          <FormField label="Último mantenimiento realizado a (h)" for-id="manual-base-hours"><input id="manual-base-hours" v-model="manualDraft.base_horas" name="base_horas" type="number" min="0" step="0.1" :class="fieldClass" /></FormField>
        </template>

        <template v-if="selectedManualEquipment">
          <FormField label="Frecuencia (días)" for-id="manual-interval-days"><input id="manual-interval-days" v-model="manualDraft.intervalo_dias" name="intervalo_dias" type="number" min="1" :class="fieldClass" /></FormField>
          <FormField label="Avisar antes (días)" for-id="manual-warning-days"><input id="manual-warning-days" v-model="manualDraft.anticipacion_dias" name="anticipacion_dias" type="number" min="0" :class="fieldClass" /></FormField>
          <FormField label="Último mantenimiento realizado el" for-id="manual-base-date"><input id="manual-base-date" v-model="manualDraft.base_fecha" name="base_fecha" type="date" :max="today()" :class="fieldClass" /></FormField>

          <section v-if="manualPreview" class="rounded-xl border border-primary/20 bg-primary-subtle p-4 md:col-span-3" data-testid="manual-preview">
            <h3 class="font-bold text-ink">Vista previa del plan</h3>
            <div class="mt-3 grid gap-4 sm:grid-cols-3">
              <div v-if="manualPreview.kilometers" class="rounded-lg bg-white/70 p-3">
                <p class="text-xs text-ink-muted">Último mantenimiento realizado a</p>
                <p class="font-semibold text-ink">{{ formatNumberEs(manualPreview.kilometers.base, 0) }} km</p>
                <p class="mt-2 text-sm text-ink-muted">Frecuencia: {{ formatNumberEs(manualPreview.kilometers.interval, 0) }} km</p>
                <p class="mt-2 font-semibold text-ink">Próximo mantenimiento: {{ formatNumberEs(manualPreview.kilometers.next, 0) }} km</p>
                <p class="text-xs text-ink-muted">Avisar desde: {{ formatNumberEs(manualPreview.kilometers.warningFrom, 0) }} km</p>
              </div>
              <div v-if="manualPreview.hours" class="rounded-lg bg-white/70 p-3">
                <p class="text-xs text-ink-muted">Último mantenimiento realizado a</p>
                <p class="font-semibold text-ink">{{ formatNumberEs(manualPreview.hours.base, 1) }} h</p>
                <p class="mt-2 text-sm text-ink-muted">Frecuencia: {{ formatNumberEs(manualPreview.hours.interval, 1) }} h</p>
                <p class="mt-2 font-semibold text-ink">Próximo mantenimiento: {{ formatNumberEs(manualPreview.hours.next, 1) }} h</p>
                <p class="text-xs text-ink-muted">Avisar desde: {{ formatNumberEs(manualPreview.hours.warningFrom, 1) }} h</p>
              </div>
              <div v-if="manualPreview.date" class="rounded-lg bg-white/70 p-3">
                <p class="text-xs text-ink-muted">Último mantenimiento realizado el</p>
                <p class="font-semibold text-ink">{{ displayDate(manualPreview.date.base) }}</p>
                <p class="mt-2 text-sm text-ink-muted">Frecuencia: {{ manualPreview.date.interval }} días</p>
                <p class="mt-2 font-semibold text-ink">Próximo mantenimiento: {{ displayDate(manualPreview.date.next) }}</p>
                <p class="text-xs text-ink-muted">Avisar desde: {{ displayDate(manualPreview.date.warningFrom) }}</p>
              </div>
            </div>
            <p class="mt-3 text-xs text-ink-muted">Vista previa orientativa. La validación definitiva se realiza al guardar.</p>
          </section>
        </template>

        <FormField label="Prioridad" for-id="manual-priority"><select id="manual-priority" name="prioridad" :class="fieldClass"><option v-for="priority in ['BAJA','MEDIA','ALTA','CRITICA']" :key="priority" :value="priority" :selected="String(old.prioridad || manualTemplateDefault?.priority || 'MEDIA') === priority">{{ priority === 'CRITICA' ? 'CRÍTICA' : priority }}</option></select></FormField>
        <FormField label="Observaciones" for-id="manual-notes" class="md:col-span-2"><input id="manual-notes" name="observaciones" maxlength="1000" :value="old.observaciones || manualTemplateDefault?.notes || ''" :class="fieldClass" /></FormField>
        <div class="md:col-span-3 flex justify-end"><button type="submit" :class="primaryButton">Crear plan manual</button></div>
      </form>
    </section>

    <section class="overflow-hidden rounded-2xl border border-border bg-white shadow-card">
      <div class="border-b border-border bg-surface-subtle p-4 sm:p-5">
        <form method="get" :action="data.routes.index" class="grid gap-3 md:grid-cols-[minmax(15rem,1.3fr)_minmax(14rem,1fr)_minmax(11rem,.8fr)_auto] md:items-end">
          <FormField label="Buscar" for-id="plans-search"><input id="plans-search" v-model="localSearch" name="q" type="search" placeholder="Patente, equipo o servicio" :class="fieldClass" /></FormField>
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
          <div class="flex gap-2"><button type="submit" :class="primaryButton">Filtrar</button><a :href="clearUrl" :class="secondaryButton">Limpiar</a></div>
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
              <th class="px-4 py-3">Equipo</th><th class="px-4 py-3">Servicio</th><th class="px-4 py-3">Frecuencia / próximo</th><th class="px-4 py-3">Prioridad</th><th class="px-4 py-3">Estado</th><th class="px-4 py-3 text-right">Acción</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border-subtle">
            <tr v-for="plan in visiblePlans" :key="plan.id">
              <td class="px-4 py-3">
                <a v-if="plan.equipment.detailUrl" :href="plan.equipment.detailUrl" class="font-bold text-primary hover:underline">{{ plan.equipment.code }}</a>
                <strong v-else class="text-ink">{{ plan.equipment.code }}</strong>
                <p class="mt-0.5 text-xs text-ink-muted">{{ [plan.equipment.plate, plan.equipment.typeName, plan.branch.code].filter(Boolean).join(' · ') }}</p>
              </td>
              <td class="px-4 py-3"><strong class="text-ink">{{ plan.serviceName }}</strong><p v-if="plan.notes" class="mt-0.5 max-w-xs truncate text-xs text-ink-muted">{{ plan.notes }}</p></td>
              <td class="px-4 py-3"><p v-for="([key, criterion]) in planCriteria(plan)" :key="key" class="text-xs text-ink">{{ criterionText(key, criterion) }}</p><span v-if="planCriteria(plan).length === 0" class="text-xs text-ink-muted">Sin frecuencia</span></td>
              <td class="px-4 py-3 text-xs font-semibold text-ink">{{ plan.priority === 'CRITICA' ? 'CRÍTICA' : plan.priority }}</td>
              <td class="px-4 py-3"><StatusBadge :status="plan.state" /></td>
              <td class="px-4 py-3 text-right"><button v-if="data.canEdit && plan.editUrl" type="button" :class="secondaryButton" :data-testid="`edit-plan-${plan.id}`" @click="openEditModal(plan)">Editar</button></td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="border-t border-border px-4 py-4 sm:px-5"><PaginationBar :pagination="data.plans.pagination" /></div>
    </section>

    <div
      v-if="editingPlan"
      class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/55 p-4"
      role="dialog"
      aria-modal="true"
      :aria-labelledby="`edit-plan-title-${editingPlan.id}`"
      data-testid="edit-plan-modal"
      @click.self="closeEditModal"
    >
      <section class="max-h-[92vh] w-full max-w-4xl overflow-y-auto rounded-2xl border border-border bg-white shadow-2xl">
        <header class="sticky top-0 z-10 flex items-start justify-between gap-4 border-b border-border bg-white px-5 py-4 sm:px-6">
          <div>
            <p class="text-xs font-bold uppercase tracking-wide text-primary">Editar plan preventivo</p>
            <h2 :id="`edit-plan-title-${editingPlan.id}`" class="mt-1 text-xl font-bold text-ink">{{ editingPlan.serviceName }}</h2>
            <p class="mt-1 text-sm text-ink-muted">{{ editingPlan.equipment.code }} · {{ [editingPlan.equipment.plate, editingPlan.equipment.typeName, editingPlan.branch.code].filter(Boolean).join(' · ') }}</p>
          </div>
          <button type="button" class="rounded-lg p-2 text-ink-muted hover:bg-surface-subtle hover:text-ink" aria-label="Cerrar edición" @click="closeEditModal"><XMarkIcon class="size-5" aria-hidden="true" /></button>
        </header>

        <form method="post" :action="editingPlan.editUrl" class="grid gap-4 p-5 md:grid-cols-3 sm:p-6">
          <CsrfInput :csrf="data.csrf" />

          <template v-if="editingEquipment?.controlsKm">
            <FormField label="Frecuencia (kilómetros)" :for-id="`edit-km-${editingPlan.id}`"><input :id="`edit-km-${editingPlan.id}`" name="intervalo_km" type="number" min="1" :value="editingPlan.criteria.kilometers?.interval" :class="fieldClass" /></FormField>
            <FormField label="Avisar antes (kilómetros)" :for-id="`edit-wkm-${editingPlan.id}`"><input :id="`edit-wkm-${editingPlan.id}`" name="anticipacion_km" type="number" min="0" :value="editingPlan.criteria.kilometers?.warning" :class="fieldClass" /></FormField>
            <FormField label="Último mantenimiento realizado a (km)" :for-id="`edit-bkm-${editingPlan.id}`"><input :id="`edit-bkm-${editingPlan.id}`" name="base_km" type="number" min="0" :value="editingPlan.criteria.kilometers?.base" :class="fieldClass" /></FormField>
          </template>

          <template v-if="editingEquipment?.controlsHours">
            <FormField label="Frecuencia (horas)" :for-id="`edit-hours-${editingPlan.id}`"><input :id="`edit-hours-${editingPlan.id}`" name="intervalo_horas" type="number" min="0.1" step="0.1" :value="editingPlan.criteria.hours?.interval" :class="fieldClass" /></FormField>
            <FormField label="Avisar antes (horas)" :for-id="`edit-whours-${editingPlan.id}`"><input :id="`edit-whours-${editingPlan.id}`" name="anticipacion_horas" type="number" min="0" step="0.1" :value="editingPlan.criteria.hours?.warning" :class="fieldClass" /></FormField>
            <FormField label="Último mantenimiento realizado a (h)" :for-id="`edit-bhours-${editingPlan.id}`"><input :id="`edit-bhours-${editingPlan.id}`" name="base_horas" type="number" min="0" step="0.1" :value="editingPlan.criteria.hours?.base" :class="fieldClass" /></FormField>
          </template>

          <FormField label="Frecuencia (días)" :for-id="`edit-days-${editingPlan.id}`"><input :id="`edit-days-${editingPlan.id}`" name="intervalo_dias" type="number" min="1" :value="editingPlan.criteria.date?.interval" :class="fieldClass" /></FormField>
          <FormField label="Avisar antes (días)" :for-id="`edit-wdays-${editingPlan.id}`"><input :id="`edit-wdays-${editingPlan.id}`" name="anticipacion_dias" type="number" min="0" :value="editingPlan.criteria.date?.warning" :class="fieldClass" /></FormField>
          <FormField label="Último mantenimiento realizado el" :for-id="`edit-bdate-${editingPlan.id}`"><input :id="`edit-bdate-${editingPlan.id}`" name="base_fecha" type="date" :max="today()" :value="editingPlan.criteria.date?.base" :class="fieldClass" /></FormField>
          <FormField label="Prioridad" :for-id="`edit-priority-${editingPlan.id}`"><select :id="`edit-priority-${editingPlan.id}`" name="prioridad" :class="fieldClass"><option v-for="priority in ['BAJA','MEDIA','ALTA','CRITICA']" :key="priority" :value="priority" :selected="priority === editingPlan.priority">{{ priority === 'CRITICA' ? 'CRÍTICA' : priority }}</option></select></FormField>
          <FormField label="Observaciones" :for-id="`edit-notes-${editingPlan.id}`" class="md:col-span-2"><input :id="`edit-notes-${editingPlan.id}`" name="observaciones" maxlength="1000" :value="editingPlan.notes" :class="fieldClass" /></FormField>

          <div class="md:col-span-3 flex flex-wrap justify-end gap-2 border-t border-border pt-4">
            <button type="button" :class="secondaryButton" @click="closeEditModal">Cancelar</button>
            <button type="submit" :class="primaryButton">Guardar cambios</button>
          </div>
        </form>
      </section>
    </div>
  </div>
</template>
