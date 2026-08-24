<script setup>
import { computed, reactive, ref } from 'vue'
import { ArrowRightIcon, MagnifyingGlassIcon, PlusIcon, WrenchScrewdriverIcon, XMarkIcon } from '@heroicons/vue/24/outline'
import CsrfInput from './components/CsrfInput.vue'
import EmptyState from './components/EmptyState.vue'
import EquipmentThumbnail from './components/EquipmentThumbnail.vue'
import FormField from './components/FormField.vue'
import PageHeading from './components/PageHeading.vue'
import PaginationBar from './components/PaginationBar.vue'
import PanelCard from './components/PanelCard.vue'
import StatusBadge from './components/StatusBadge.vue'
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
const activeAction = ref(null)
const planForms = reactive({})
const closeForms = reactive({})
const activeCloseOrder = ref(null)
const correctiveModalOpen = ref(false)
const correctiveEquipmentId = ref('')
const correctiveSearch = ref('')
const correctiveOrderUrl = computed(() => String(props.data.routes.createEquipment ?? '').replace(/\/equipos\/?$/, '/ordenes/correctivas'))
const quickReadingsUrl = computed(() => String(props.data.routes.createEquipment ?? '').replace(/\/equipos\/?$/, '/lecturas/rapidas'))

const normalizeSearch = (value) => String(value ?? '').toLocaleLowerCase('es').replace(/[\s-]+/g, '')
const filteredCorrectiveEquipments = computed(() => {
  const term = normalizeSearch(correctiveSearch.value)
  const equipments = props.data.equipments ?? []
  if (!term) return equipments
  return equipments.filter((equipment) => [equipment.code, equipment.plate, equipment.typeName, equipment.branchName]
    .some((value) => normalizeSearch(value).includes(term)))
})
const selectedCorrectiveEquipment = computed(() => (props.data.equipments ?? []).find((equipment) => String(equipment.id) === String(correctiveEquipmentId.value)) ?? null)

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
const openCorrectiveModal = (equipment = null) => {
  correctiveEquipmentId.value = equipment ? String(equipment.id) : ''
  correctiveSearch.value = equipment ? [equipment.code, equipment.plate].filter(Boolean).join(' · ') : ''
  correctiveModalOpen.value = true
}
const closeCorrectiveModal = () => {
  correctiveModalOpen.value = false
  correctiveEquipmentId.value = ''
  correctiveSearch.value = ''
}
const selectCorrectiveEquipment = (equipment) => {
  correctiveEquipmentId.value = String(equipment.id)
  correctiveSearch.value = [equipment.code, equipment.plate].filter(Boolean).join(' · ')
}
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
      trabajo_realizado_correctivo: '',
      costo_mano_obra: '0',
      costo_repuestos: '0',
      otros_costos: '0',
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
          <p class="mt-1 text-sm text-ink-muted">Accedé a las tareas operativas frecuentes sin perder de vista la jornada.</p>
        </div>
        <div class="flex flex-wrap gap-2">
          <button v-if="data.can.editOrder" type="button" :class="primaryButton" aria-haspopup="dialog" @click="openCorrectiveModal()"><PlusIcon class="mr-2 size-4" aria-hidden="true" />Nueva OT correctiva</button>
          <a v-if="data.can.registerReading" :href="quickReadingsUrl" :class="secondaryButton">Registrar lectura</a>
          <a :href="data.routes.equipmentIndex" :class="secondaryButton">Administrar equipos</a>
        </div>
      </div>
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
        <EmptyState v-if="data.orders.length === 0" title="Todavía no hay órdenes" description="Las órdenes generadas aparecerán acá." />
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
          <div class="mt-4 flex flex-wrap gap-2"><a :href="equipment.routes.detail" class="inline-flex min-h-10 items-center text-sm font-semibold text-primary hover:text-primary-hover">Ver ficha<ArrowRightIcon class="ml-1 size-4" aria-hidden="true" /></a><button v-if="data.can.registerReading" type="button" :class="secondaryButton" :aria-expanded="isActionOpen(`reading-${equipment.id}`)" :aria-controls="`reading-${equipment.id}`" @click="toggleAction(`reading-${equipment.id}`)">Registrar lectura</button><button v-if="data.can.assignPlan" type="button" :class="secondaryButton" :aria-expanded="isActionOpen(`plan-${equipment.id}`)" :aria-controls="`plan-${equipment.id}`" @click="toggleAction(`plan-${equipment.id}`)">Asignar plan</button><button v-if="data.can.editOrder" type="button" :class="secondaryButton" aria-haspopup="dialog" @click="openCorrectiveModal(equipment)"><WrenchScrewdriverIcon class="mr-2 size-4" aria-hidden="true" />Nueva OT correctiva</button></div>

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

    <div v-if="correctiveModalOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" role="presentation" @click.self="closeCorrectiveModal">
      <section class="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-2xl bg-surface-raised shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="corrective-modal-title">
        <div class="flex items-start justify-between gap-4 border-b border-border-subtle px-5 py-4 sm:px-6">
          <div><p class="text-xs font-bold uppercase tracking-wide text-primary">Orden de trabajo</p><h2 id="corrective-modal-title" class="mt-1 text-xl font-bold text-ink">Nueva OT correctiva</h2><p class="mt-1 text-sm text-ink-muted">Registrá la falla o intervención sin salir del centro operativo.</p></div>
          <button type="button" class="rounded-lg p-2 text-ink-muted hover:bg-surface-muted hover:text-ink" aria-label="Cerrar modal" @click="closeCorrectiveModal"><XMarkIcon class="size-5" aria-hidden="true" /></button>
        </div>
        <form method="post" :action="correctiveOrderUrl" class="grid gap-4 p-5 sm:grid-cols-2 sm:p-6">
          <CsrfInput :csrf="data.csrf" />
          <input type="hidden" name="equipo_id" :value="correctiveEquipmentId" />
          <FormField label="Equipo *" for-id="corrective-equipment-search" class="sm:col-span-2">
            <div class="relative"><MagnifyingGlassIcon class="pointer-events-none absolute left-3 top-3 size-5 text-ink-subtle" aria-hidden="true" /><input id="corrective-equipment-search" v-model="correctiveSearch" type="search" autocomplete="off" required placeholder="Buscar por código o patente" :class="`${fieldClass} pl-10`" @input="correctiveEquipmentId = ''" /></div>
            <div v-if="!selectedCorrectiveEquipment" class="mt-2 max-h-44 overflow-y-auto rounded-xl border border-border bg-surface">
              <button v-for="equipment in filteredCorrectiveEquipments" :key="equipment.id" type="button" class="flex w-full items-center justify-between gap-3 border-b border-border-subtle px-3 py-2 text-left last:border-0 hover:bg-surface-muted" @click="selectCorrectiveEquipment(equipment)"><span><strong class="text-sm text-ink">{{ equipment.code }}</strong><span v-if="equipment.plate" class="ml-2 text-xs text-ink-muted">{{ equipment.plate }}</span></span><span class="text-xs text-ink-subtle">{{ equipment.typeName }}</span></button>
              <p v-if="filteredCorrectiveEquipments.length === 0" class="px-3 py-3 text-sm text-ink-muted">No se encontraron equipos.</p>
            </div>
            <p v-else class="mt-2 rounded-lg bg-success-subtle px-3 py-2 text-sm font-semibold text-success-strong">Seleccionado: {{ selectedCorrectiveEquipment.code }}<span v-if="selectedCorrectiveEquipment.plate"> · {{ selectedCorrectiveEquipment.plate }}</span></p>
          </FormField>
          <FormField label="Fecha *" for-id="corrective-date"><input id="corrective-date" type="date" name="fecha_apertura" :value="today()" required :class="fieldClass" /></FormField>
          <FormField label="Prioridad *" for-id="corrective-priority"><select id="corrective-priority" name="prioridad" required :class="fieldClass"><option value="MEDIA" selected>Normal</option><option value="ALTA">Alta</option><option value="CRITICA">Urgente</option></select></FormField>
          <FormField label="Problema / motivo *" for-id="corrective-problem" hint="Mínimo 5 caracteres." class="sm:col-span-2"><textarea id="corrective-problem" name="problema_reportado" rows="3" minlength="5" maxlength="3000" required placeholder="Ej.: pérdida de aceite hidráulico en línea de retorno" :class="fieldClass"></textarea></FormField>
          <FormField label="Responsable" for-id="corrective-owner"><select id="corrective-owner" name="responsable_usuario_id" :class="fieldClass"><option value="">Sin asignar</option><option v-for="user in data.catalogs.users" :key="user.id" :value="user.id">{{ user.name }}</option></select></FormField>
          <FormField label="Observaciones" for-id="corrective-observations"><textarea id="corrective-observations" name="observaciones" rows="2" maxlength="3000" placeholder="Dato adicional opcional" :class="fieldClass"></textarea></FormField>
          <template v-if="selectedCorrectiveEquipment">
            <FormField v-if="selectedCorrectiveEquipment.controlsKm" label="Km de ingreso" for-id="corrective-km"><input id="corrective-km" type="number" min="0" name="km_ingreso" :value="selectedCorrectiveEquipment.currentKm ?? ''" :class="fieldClass" /></FormField>
            <FormField v-if="selectedCorrectiveEquipment.controlsHours" label="Horómetro de ingreso" for-id="corrective-hours"><input id="corrective-hours" type="number" min="0" step="0.1" name="horas_ingreso" :value="selectedCorrectiveEquipment.currentHours ?? ''" :class="fieldClass" /></FormField>
          </template>
          <div class="flex flex-col-reverse gap-2 border-t border-border-subtle pt-4 sm:col-span-2 sm:flex-row sm:justify-end"><button type="button" :class="secondaryButton" @click="closeCorrectiveModal">Cancelar</button><button type="submit" :disabled="!correctiveEquipmentId" :class="primaryButton"><PlusIcon class="mr-2 size-4" aria-hidden="true" />Crear OT</button></div>
        </form>
      </section>
    </div>

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
