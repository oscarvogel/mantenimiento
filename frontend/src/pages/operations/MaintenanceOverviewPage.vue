<script setup>
import { computed, reactive, ref } from 'vue'
import { ArrowRightIcon, PlusIcon, WrenchScrewdriverIcon } from '@heroicons/vue/24/outline'
import CsrfInput from './components/CsrfInput.vue'
import EmptyState from './components/EmptyState.vue'
import EquipmentThumbnail from './components/EquipmentThumbnail.vue'
import FormField from './components/FormField.vue'
import PageHeading from './components/PageHeading.vue'
import PaginationBar from './components/PaginationBar.vue'
import PanelCard from './components/PanelCard.vue'
import StatusBadge from './components/StatusBadge.vue'
import UsageReadingInput from './components/UsageReadingInput.vue'
import WorkOrderClosureModal from './components/WorkOrderClosureModal.vue'
import { fieldClass, formatHours, formatKilometers, formatReadingOrigin, primaryButton, secondaryButton, today } from './helpers.js'

const props = defineProps({ data: { type: Object, required: true } })
const data = computed(() => ({
  ...props.data,
  readings: (props.data.readings ?? []).map((reading) => ({
    ...reading,
    kilometers: reading.kilometers === null ? '—' : formatKilometers(reading.kilometers),
    hours: reading.hours === null ? '—' : formatHours(reading.hours),
    origin: formatReadingOrigin(reading.origin),
  })),
}))
const activeAction = ref(Object.keys(props.data.old ?? {}).length > 0 ? 'create-equipment' : null)
const planForms = reactive({})
const closeForms = reactive({})
const activeCloseOrder = ref(null)

const visiblePlanCounts = computed(() => {
  const counts = { PROXIMO: 0, VENCIDO: 0, SIN_DATOS: 0 }
  for (const plan of props.data.plans ?? []) {
    const state = plan.computedState || 'SIN_DATOS'
    if (Object.hasOwn(counts, state)) counts[state] += 1
  }
  return counts
})

const toggleAction = (action) => {
  activeAction.value = activeAction.value === action ? null : action
}
const isActionOpen = (action) => activeAction.value === action
const valueOrBlank = (value) => (value === null || value === undefined ? '' : String(value))
const templateDefaults = computed(() => props.data.catalogs.templateDefaults ?? [])
const defaultsForEquipment = (equipment) => templateDefaults.value.filter((item) => !item.equipmentTypeId || Number(item.equipmentTypeId) === Number(equipment.typeId))
const defaultFor = (equipment, serviceId) => defaultsForEquipment(equipment).find((item) => String(item.serviceTypeId) === String(serviceId)) ?? null
const applyDefaultToState = (state, templateDefault) => {
  if (!templateDefault) return
  state.intervalo_km = valueOrBlank(templateDefault.intervalKm)
  state.anticipacion_km = valueOrBlank(templateDefault.warningKm)
  state.intervalo_horas = valueOrBlank(templateDefault.intervalHours)
  state.anticipacion_horas = valueOrBlank(templateDefault.warningHours)
  state.intervalo_dias = valueOrBlank(templateDefault.intervalDays)
  state.anticipacion_dias = valueOrBlank(templateDefault.warningDays)
  state.prioridad = templateDefault.priority || 'MEDIA'
  state.observaciones = templateDefault.notes || ''
}
const initialServiceId = (equipment) => {
  const defaults = defaultsForEquipment(equipment)
  if (defaults.length > 0) return String(defaults[0].serviceTypeId)
  return props.data.catalogs.serviceTypes[0] ? String(props.data.catalogs.serviceTypes[0].id) : ''
}
const stateFor = (equipment) => {
  if (!planForms[equipment.id]) {
    const serviceId = initialServiceId(equipment)
    planForms[equipment.id] = {
      serviceId,
      intervalo_km: '',
      anticipacion_km: '',
      intervalo_horas: '',
      anticipacion_horas: '',
      intervalo_dias: '',
      anticipacion_dias: '',
      prioridad: 'MEDIA',
      observaciones: '',
    }
    applyDefaultToState(planForms[equipment.id], defaultFor(equipment, serviceId))
  }
  return planForms[equipment.id]
}
const selectedPlanDefault = (equipment) => defaultFor(equipment, stateFor(equipment).serviceId)
const changePlanService = (equipment) => applyDefaultToState(stateFor(equipment), selectedPlanDefault(equipment))
const closeStateFor = (order) => {
  if (!closeForms[order.id]) {
    closeForms[order.id] = {
      kilometers: '',
      hours: '',
      currentKm: order.currentKm,
      currentHours: order.currentHours,
      tasks: {},
    }
  }
  for (const task of order.tasks ?? []) {
    if (!closeForms[order.id].tasks[task.id]) {
      closeForms[order.id].tasks[task.id] = { resultado: '', detalle: '' }
    }
  }
  return closeForms[order.id]
}
const openCloseModal = (order) => {
  closeStateFor(order)
  activeCloseOrder.value = order
}
const closeCloseModal = () => { activeCloseOrder.value = null }
const updateCloseForm = (value) => {
  if (activeCloseOrder.value) closeForms[activeCloseOrder.value.id] = value
}
for (const equipment of props.data.equipments ?? []) stateFor(equipment)
for (const order of props.data.orders ?? []) closeStateFor(order)
</script>

<template>
  <div>
    <PageHeading eyebrow="Centro operativo" title="Qué requiere atención hoy" description="Priorizá vencimientos, avisos y órdenes antes de avanzar con tareas administrativas.">
      <template #actions>
        <a :href="data.routes.equipmentIndex" :class="secondaryButton">Ver todos los equipos<ArrowRightIcon class="ml-2 size-4" aria-hidden="true" /></a>
      </template>
    </PageHeading>

    <section aria-label="Resumen operativo" class="mb-6 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
      <article class="rounded-xl border border-danger/20 bg-danger-subtle/40 p-4">
        <p class="text-xs font-bold uppercase tracking-wide text-danger-strong">Avisos pendientes</p>
        <p class="mt-2 text-3xl font-bold text-ink">{{ data.pagination.notices.total }}</p>
      </article>
      <article class="rounded-xl border border-warning/30 bg-warning-subtle/50 p-4">
        <p class="text-xs font-bold uppercase tracking-wide text-warning-foreground">Próximos en esta página</p>
        <p class="mt-2 text-3xl font-bold text-ink">{{ visiblePlanCounts.PROXIMO }}</p>
      </article>
      <article class="rounded-xl border border-info/20 bg-info-subtle/50 p-4">
        <p class="text-xs font-bold uppercase tracking-wide text-info-strong">Órdenes visibles</p>
        <p class="mt-2 text-3xl font-bold text-ink">{{ data.pagination.orders.total }}</p>
      </article>
      <article class="rounded-xl border border-border bg-surface-raised p-4">
        <p class="text-xs font-bold uppercase tracking-wide text-ink-muted">Planes sin datos</p>
        <p class="mt-2 text-3xl font-bold text-ink">{{ visiblePlanCounts.SIN_DATOS }}</p>
      </article>
    </section>

    <section class="mb-6 rounded-xl border border-border bg-surface-raised p-4 sm:p-5" aria-labelledby="quick-actions-title">
      <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
        <div>
          <h2 id="quick-actions-title" class="font-bold text-ink">Acciones rápidas</h2>
          <p class="mt-1 text-sm text-ink-muted">Abrí una sola tarea a la vez para mantener el foco.</p>
        </div>
        <div class="flex flex-wrap gap-2">
          <button v-if="data.can.createEquipment" type="button" :class="isActionOpen('create-equipment') ? primaryButton : secondaryButton" :aria-expanded="isActionOpen('create-equipment')" aria-controls="overview-create-equipment" @click="toggleAction('create-equipment')"><PlusIcon class="mr-2 size-4" aria-hidden="true" />Nuevo equipo</button>
          <a :href="data.routes.equipmentIndex" :class="secondaryButton">Administrar equipos</a>
        </div>
      </div>

      <form v-if="data.can.createEquipment && isActionOpen('create-equipment')" id="overview-create-equipment" method="post" :action="data.routes.createEquipment" class="mt-5 grid gap-4 border-t border-border-subtle pt-5 sm:grid-cols-2 xl:grid-cols-4">
        <CsrfInput :csrf="data.csrf" />
        <FormField label="Código" for-id="overview-equipment-code"><input id="overview-equipment-code" name="codigo" maxlength="50" required :value="data.old?.codigo" class="uppercase" :class="fieldClass" /></FormField>
        <FormField label="Patente" for-id="overview-equipment-plate"><input id="overview-equipment-plate" name="patente" maxlength="20" :value="data.old?.patente" class="uppercase" :class="fieldClass" /></FormField>
        <FormField label="Sucursal" for-id="overview-equipment-branch"><select id="overview-equipment-branch" name="sucursal_id" required :class="fieldClass"><option v-for="branch in data.catalogs.branches" :key="branch.id" :value="branch.id">{{ branch.code }} · {{ branch.name }}</option></select></FormField>
        <FormField label="Tipo" for-id="overview-equipment-type"><select id="overview-equipment-type" name="tipo_equipo_id" required :class="fieldClass"><option v-for="type in data.catalogs.equipmentTypes" :key="type.id" :value="type.id">{{ type.name }}</option></select></FormField>
        <FormField label="Fecha de alta" for-id="overview-equipment-date"><input id="overview-equipment-date" type="date" name="fecha_alta" required :value="data.old?.fecha_alta || today()" :class="fieldClass" /></FormField>
        <FormField label="Marca" for-id="overview-equipment-brand"><select id="overview-equipment-brand" name="marca_id" :class="fieldClass"><option value="">Sin informar</option><option v-for="brand in data.catalogs.brands" :key="brand.id" :value="brand.id">{{ brand.name }}</option></select></FormField>
        <FormField label="Modelo" for-id="overview-equipment-model"><select id="overview-equipment-model" name="modelo_id" :class="fieldClass"><option value="">Sin informar</option><option v-for="model in data.catalogs.models" :key="model.id" :value="model.id">{{ model.brandName }} · {{ model.name }} · {{ model.typeName }}</option></select></FormField>
        <FormField label="Año" for-id="overview-equipment-year"><input id="overview-equipment-year" type="number" min="1900" max="2100" name="anio" :value="data.old?.anio" :class="fieldClass" /></FormField>
        <FormField label="Chasis" for-id="overview-equipment-chassis"><input id="overview-equipment-chassis" name="chasis" maxlength="100" :value="data.old?.chasis" :class="fieldClass" /></FormField>
        <FormField label="Motor" for-id="overview-equipment-engine"><input id="overview-equipment-engine" name="motor" maxlength="100" :value="data.old?.motor" :class="fieldClass" /></FormField>
        <FormField label="Observaciones" for-id="overview-equipment-notes" class="sm:col-span-2"><input id="overview-equipment-notes" name="observaciones" maxlength="500" :value="data.old?.observaciones" :class="fieldClass" /></FormField>
        <button type="submit" :class="`${primaryButton} self-end`">Crear equipo</button>
      </form>
    </section>

    <div class="mb-6 grid gap-6 xl:grid-cols-[minmax(0,1.65fr)_minmax(18rem,0.8fr)]">
      <PanelCard title="Atención requerida" :count="data.pagination.notices.total">
        <EmptyState v-if="data.notices.length === 0 && data.plans.length === 0" title="No hay atención pendiente" description="Los vencimientos y planes próximos aparecerán acá." />
        <ul v-if="data.notices.length" class="divide-y divide-border-subtle">
          <li v-for="notice in data.notices" :key="notice.id" class="flex flex-col gap-4 py-4 first:pt-0 lg:flex-row lg:items-center lg:justify-between">
            <div class="flex items-start gap-3"><StatusBadge status="VENCIDO" /><div><strong class="text-ink">{{ notice.equipmentCode }} · {{ notice.serviceName }}</strong><p class="mt-1 text-sm text-danger-strong">Vencido por {{ notice.triggerCriteria }}</p></div></div>
            <form v-if="data.can.generateOrder" method="post" :action="notice.generateOrderUrl" data-confirm data-confirm-title="¿Generar la orden de trabajo?" data-confirm-text="Se creará una orden de trabajo para este vencimiento y su responsable." data-confirm-button="Generar OT" class="flex flex-col gap-2 sm:flex-row"><CsrfInput :csrf="data.csrf" /><label class="sr-only" :for="`notice-owner-${notice.id}`">Responsable</label><select :id="`notice-owner-${notice.id}`" name="responsable_usuario_id" :class="fieldClass"><option v-for="user in data.catalogs.users" :key="user.id" :value="user.id">{{ user.name }}</option></select><button type="submit" :class="primaryButton">Generar OT</button></form>
          </li>
        </ul>
        <div v-if="data.plans.length" class="mt-2 divide-y divide-border-subtle border-t border-border-subtle">
          <article v-for="plan in data.plans" :key="plan.id" class="flex items-center justify-between gap-3 py-4"><div class="flex items-center gap-3"><EquipmentThumbnail :url="plan.photoUrl" :code="plan.equipmentCode" size="sm" /><div><strong class="text-ink">{{ plan.equipmentCode }}</strong><p class="mt-1 text-sm text-ink-muted">{{ plan.serviceName }}</p><p class="mt-1 text-xs text-ink-subtle">Próximo: <span v-if="plan.nextKm !== null">{{ plan.nextKm }} km </span><span v-if="plan.nextHours !== null">{{ plan.nextHours }} h </span><span v-if="plan.nextDate !== null">{{ plan.nextDate }}</span></p></div></div><StatusBadge :status="plan.computedState || 'SIN_DATOS'" /></article>
        </div>
        <PaginationBar :pagination="data.pagination.notices" />
        <PaginationBar :pagination="data.pagination.plans" />
      </PanelCard>

      <PanelCard title="Órdenes de trabajo" :count="data.pagination.orders.total">
        <EmptyState v-if="data.orders.length === 0" title="Todavía no hay órdenes" description="Las órdenes generadas desde avisos aparecerán acá." />
        <div v-else class="space-y-4">
          <article v-for="order in data.orders" :key="order.id" class="rounded-xl border border-border p-4">
            <div class="flex items-start justify-between gap-3"><div class="flex items-center gap-3"><EquipmentThumbnail :url="order.photoUrl" :code="order.equipmentCode" /><strong class="text-ink">{{ order.number }} · {{ order.equipmentCode }}</strong></div><StatusBadge :status="order.status" /></div>
            <p class="mt-2 text-sm text-ink-muted">{{ order.serviceName || 'Servicio preventivo' }} · Responsable: {{ order.ownerName || 'Sin asignar' }}</p>
            <ul v-if="order.tasks.length" class="mt-3 space-y-1 text-sm text-ink"><li v-for="task in order.tasks" :key="task.id">{{ task.description }} <span class="text-ink-muted">({{ task.status }})</span></li></ul>
            <form v-if="order.status === 'EMITIDA' && data.can.editOrder" method="post" :action="order.startUrl" class="mt-4"><CsrfInput :csrf="data.csrf" /><button type="submit" :class="secondaryButton">Iniciar orden</button></form>
            <button v-else-if="order.status === 'EN_PROCESO' && data.can.closeOrder" type="button" :class="`${secondaryButton} mt-4`" aria-haspopup="dialog" @click="openCloseModal(order)"><WrenchScrewdriverIcon class="mr-2 size-4" aria-hidden="true" />Cerrar orden</button>
          </article>
        </div>
        <PaginationBar :pagination="data.pagination.orders" />
      </PanelCard>
    </div>

    <PanelCard title="Equipos y carga operativa" :count="data.pagination.equipments.total" class="mb-6">
      <p class="mb-5 text-sm text-ink-muted">Consultá la ficha o abrí únicamente la carga que necesitás para cada equipo.</p>
      <EmptyState v-if="data.equipments.length === 0" title="Todavía no hay equipos" description="No hay unidades dentro de tu alcance actual." />
      <div v-else class="grid gap-4 xl:grid-cols-2">
        <article v-for="equipment in data.equipments" :key="equipment.id" class="rounded-xl border border-border p-4 sm:p-5">
          <div class="flex items-start justify-between gap-3"><div class="flex gap-3"><EquipmentThumbnail :url="equipment.photoUrl" :code="equipment.code" /><div><h3 class="font-bold text-ink">{{ equipment.code }}</h3><p class="mt-1 text-sm text-ink-muted">{{ equipment.typeName }} · {{ equipment.branchName }}</p></div></div><StatusBadge :status="equipment.status" /></div>
          <dl class="mt-4 flex flex-wrap gap-2 text-xs"><div v-if="equipment.controlsKm" class="rounded-lg bg-surface-muted px-3 py-2"><dt class="inline text-ink-muted">Km: </dt><dd class="inline font-semibold text-ink">{{ equipment.currentKm ?? 'sin datos' }}</dd></div><div v-if="equipment.controlsHours" class="rounded-lg bg-surface-muted px-3 py-2"><dt class="inline text-ink-muted">Horas: </dt><dd class="inline font-semibold text-ink">{{ equipment.currentHours ?? 'sin datos' }}</dd></div><div v-if="equipment.plate" class="rounded-lg bg-surface-muted px-3 py-2"><dt class="inline text-ink-muted">Patente: </dt><dd class="inline font-semibold text-ink">{{ equipment.plate }}</dd></div></dl>
          <div class="mt-4 flex flex-wrap gap-2"><a :href="equipment.routes.detail" class="inline-flex min-h-10 items-center text-sm font-semibold text-primary hover:text-primary-hover">Ver ficha<ArrowRightIcon class="ml-1 size-4" aria-hidden="true" /></a><button v-if="data.can.registerReading" type="button" :class="secondaryButton" :aria-expanded="isActionOpen(`reading-${equipment.id}`)" :aria-controls="`reading-${equipment.id}`" @click="toggleAction(`reading-${equipment.id}`)">Registrar lectura</button><button v-if="data.can.assignPlan" type="button" :class="secondaryButton" :aria-expanded="isActionOpen(`plan-${equipment.id}`)" :aria-controls="`plan-${equipment.id}`" @click="toggleAction(`plan-${equipment.id}`)">Asignar plan</button></div>

<form v-if="data.can.registerReading && isActionOpen(`reading-${equipment.id}`)" :id="`reading-${equipment.id}`" method="post" :action="equipment.routes.registerReading" class="mt-5 grid gap-3 border-t border-border-subtle pt-5 sm:grid-cols-3"><CsrfInput :csrf="data.csrf" /><FormField v-if="equipment.controlsKm" label="Kilometraje total actual" :for-id="`reading-km-${equipment.id}`"><span class="mb-1 block text-xs font-normal text-ink-muted">Último: {{ formatKilometers(equipment.currentKm) }}</span><input :id="`reading-km-${equipment.id}`" type="text" inputmode="numeric" name="kilometraje" :class="fieldClass" /></FormField><FormField v-if="equipment.controlsHours" label="Horómetro total actual" :for-id="`reading-hours-${equipment.id}`"><span class="mb-1 block text-xs font-normal text-ink-muted">Último: {{ formatHours(equipment.currentHours) }}</span><input :id="`reading-hours-${equipment.id}`" type="text" inputmode="decimal" name="horometro" :class="fieldClass" /></FormField><input type="hidden" name="fecha_lectura" :value="data.currentDateTime" /><button type="submit" :class="`${secondaryButton} self-end`">Cargar lectura</button></form>

          <form v-if="data.can.assignPlan && isActionOpen(`plan-${equipment.id}`)" :id="`plan-${equipment.id}`" method="post" :action="equipment.routes.assignPlan" class="mt-5 grid gap-3 border-t border-border-subtle pt-5 sm:grid-cols-2"><CsrfInput :csrf="data.csrf" /><input type="hidden" name="prioridad" :value="stateFor(equipment).prioridad" /><input type="hidden" name="observaciones" :value="stateFor(equipment).observaciones" /><FormField label="Servicio" :for-id="`plan-service-${equipment.id}`" class="sm:col-span-2"><select :id="`plan-service-${equipment.id}`" v-model="stateFor(equipment).serviceId" name="tipo_servicio_id" required :class="fieldClass" @change="changePlanService(equipment)"><option v-for="service in data.catalogs.serviceTypes" :key="service.id" :value="String(service.id)">{{ service.name }}</option></select></FormField><p v-if="selectedPlanDefault(equipment)" class="rounded-lg bg-success-subtle p-3 text-sm font-semibold text-success-strong sm:col-span-2">{{ selectedPlanDefault(equipment).templateName }} · intervalos precargados</p><template v-if="equipment.controlsKm"><FormField label="Cada km" :for-id="`plan-km-${equipment.id}`"><input :id="`plan-km-${equipment.id}`" v-model="stateFor(equipment).intervalo_km" type="number" min="1" name="intervalo_km" :class="fieldClass" /></FormField><FormField label="Avisar antes (km)" :for-id="`plan-km-warning-${equipment.id}`"><input :id="`plan-km-warning-${equipment.id}`" v-model="stateFor(equipment).anticipacion_km" type="number" min="0" name="anticipacion_km" :class="fieldClass" /></FormField></template><template v-if="equipment.controlsHours"><FormField label="Cada horas" :for-id="`plan-hours-${equipment.id}`"><input :id="`plan-hours-${equipment.id}`" v-model="stateFor(equipment).intervalo_horas" type="number" min="0.1" step="0.1" name="intervalo_horas" :class="fieldClass" /></FormField><FormField label="Avisar antes (h)" :for-id="`plan-hours-warning-${equipment.id}`"><input :id="`plan-hours-warning-${equipment.id}`" v-model="stateFor(equipment).anticipacion_horas" type="number" min="0" step="0.1" name="anticipacion_horas" :class="fieldClass" /></FormField></template><FormField label="Cada días" :for-id="`plan-days-${equipment.id}`"><input :id="`plan-days-${equipment.id}`" v-model="stateFor(equipment).intervalo_dias" type="number" min="1" name="intervalo_dias" :class="fieldClass" /></FormField><FormField label="Avisar antes (días)" :for-id="`plan-days-warning-${equipment.id}`"><input :id="`plan-days-warning-${equipment.id}`" v-model="stateFor(equipment).anticipacion_dias" type="number" min="0" name="anticipacion_dias" :class="fieldClass" /></FormField><button type="submit" :class="primaryButton">Crear plan</button></form>
        </article>
      </div>
      <PaginationBar :pagination="data.pagination.equipments" />
    </PanelCard>

    <PanelCard title="Historial reciente de lecturas" flush>
      <EmptyState v-if="data.readings.length === 0" title="Sin lecturas registradas" />
      <div v-else class="overflow-x-auto"><table class="w-full min-w-[40rem] text-left text-sm"><thead class="bg-surface-subtle text-xs uppercase tracking-wide text-ink-muted"><tr><th class="px-6 py-3">Equipo</th><th class="px-6 py-3">Fecha</th><th class="px-6 py-3">Km</th><th class="px-6 py-3">Horas</th><th class="px-6 py-3">Origen</th></tr></thead><tbody class="divide-y divide-border-subtle"><tr v-for="reading in data.readings" :key="reading.id"><td class="px-6 py-4 font-semibold text-ink">{{ reading.equipmentCode }}</td><td class="px-6 py-4 text-ink-muted">{{ reading.recordedAt }}</td><td class="px-6 py-4">{{ reading.kilometers ?? '—' }}</td><td class="px-6 py-4">{{ reading.hours ?? '—' }}</td><td class="px-6 py-4 text-ink-muted">{{ reading.origin }}</td></tr></tbody></table></div>
      <PaginationBar :pagination="data.pagination.readings" />
    </PanelCard>

    <WorkOrderClosureModal
      v-if="activeCloseOrder"
      :order="activeCloseOrder"
      :csrf="data.csrf"
      :form-state="closeStateFor(activeCloseOrder)"
      @close="closeCloseModal"
      @update:form-state="updateCloseForm"
    />
  </div>
</template>
