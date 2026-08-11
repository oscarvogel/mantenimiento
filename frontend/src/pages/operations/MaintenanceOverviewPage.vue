<script setup>
import { computed, reactive } from 'vue'
import { ArrowRightIcon, BoltIcon, PlusIcon } from '@heroicons/vue/24/outline'
import CsrfInput from './components/CsrfInput.vue'
import EmptyState from './components/EmptyState.vue'
import FlashMessages from './components/FlashMessages.vue'
import FormField from './components/FormField.vue'
import PageHeading from './components/PageHeading.vue'
import PaginationBar from './components/PaginationBar.vue'
import PanelCard from './components/PanelCard.vue'
import StatusBadge from './components/StatusBadge.vue'
import { fieldClass, primaryButton, secondaryButton, today } from './helpers.js'

const props = defineProps({ data: { type: Object, required: true } })
const planForms = reactive({})
const valueOrBlank = (value) => (value === null || value === undefined ? '' : String(value))
const templateDefaults = computed(() => props.data.catalogs.templateDefaults ?? [])
const defaultsForEquipment = (equipment) => templateDefaults.value.filter((item) => Number(item.equipmentTypeId) === Number(equipment.typeId))
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
for (const equipment of props.data.equipments ?? []) stateFor(equipment)
</script>

<template>
  <div>
    <PageHeading eyebrow="Circuito vertical" title="Mantenimiento preventivo" description="Equipo → lectura → plan → vencimiento → orden → cierre → próximo servicio.">
      <template #actions><a :href="data.routes.equipmentIndex" :class="secondaryButton">Ver equipos<ArrowRightIcon class="ml-2 size-4" aria-hidden="true" /></a></template>
    </PageHeading>
    <FlashMessages :flash="data.flash" />

    <PanelCard title="1. Equipos y lecturas" :count="data.equipments.length" class="mb-6">
      <details v-if="data.can.createEquipment" class="mb-6 rounded-xl border border-border bg-surface-subtle p-4 open:bg-white sm:p-5">
        <summary class="flex cursor-pointer list-none items-center gap-2 font-semibold text-primary"><PlusIcon class="size-5" aria-hidden="true" />Crear equipo</summary>
        <form method="post" :action="data.routes.createEquipment" class="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
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
      </details>

      <EmptyState v-if="data.equipments.length === 0" title="Todavía no hay equipos" description="No hay unidades dentro de tu alcance actual." />
      <div v-else class="grid gap-4 xl:grid-cols-2">
        <article v-for="equipment in data.equipments" :key="equipment.id" class="rounded-xl border border-border p-5">
          <div class="flex items-start justify-between gap-3"><div><h3 class="font-bold text-ink">{{ equipment.code }}</h3><p class="mt-1 text-sm text-ink-muted">{{ equipment.typeName }} · {{ equipment.branchName }}</p></div><StatusBadge :status="equipment.status" /></div>
          <dl class="mt-4 flex flex-wrap gap-2 text-xs"><div v-if="equipment.controlsKm" class="rounded-lg bg-surface-muted px-3 py-2"><dt class="inline text-ink-muted">Km: </dt><dd class="inline font-semibold text-ink">{{ equipment.currentKm ?? 'sin datos' }}</dd></div><div v-if="equipment.controlsHours" class="rounded-lg bg-surface-muted px-3 py-2"><dt class="inline text-ink-muted">Horas: </dt><dd class="inline font-semibold text-ink">{{ equipment.currentHours ?? 'sin datos' }}</dd></div><div v-if="equipment.plate" class="rounded-lg bg-surface-muted px-3 py-2"><dt class="inline text-ink-muted">Patente: </dt><dd class="inline font-semibold text-ink">{{ equipment.plate }}</dd></div></dl>
          <a :href="equipment.routes.detail" class="mt-4 inline-flex text-sm font-semibold text-primary hover:text-primary-hover">Ver ficha e historial<ArrowRightIcon class="ml-1 size-4" aria-hidden="true" /></a>

          <form v-if="data.can.registerReading" method="post" :action="equipment.routes.registerReading" class="mt-5 grid gap-3 border-t border-border-subtle pt-5 sm:grid-cols-3">
            <CsrfInput :csrf="data.csrf" /><FormField label="Kilómetros" :for-id="`reading-km-${equipment.id}`"><input :id="`reading-km-${equipment.id}`" type="number" min="0" name="kilometraje" :disabled="!equipment.controlsKm" :class="fieldClass" /></FormField><FormField label="Horómetro" :for-id="`reading-hours-${equipment.id}`"><input :id="`reading-hours-${equipment.id}`" type="number" min="0" step="0.1" name="horometro" :disabled="!equipment.controlsHours" :class="fieldClass" /></FormField><input type="hidden" name="fecha_lectura" :value="data.currentDateTime" /><button type="submit" :class="`${secondaryButton} self-end`">Cargar lectura</button>
          </form>

          <details v-if="data.can.assignPlan" class="mt-4 border-t border-border-subtle pt-4">
            <summary class="cursor-pointer font-semibold text-primary">Asignar plan preventivo</summary>
            <form method="post" :action="equipment.routes.assignPlan" class="mt-4 grid gap-3 sm:grid-cols-2">
              <CsrfInput :csrf="data.csrf" />
              <input type="hidden" name="prioridad" :value="stateFor(equipment).prioridad" />
              <input type="hidden" name="observaciones" :value="stateFor(equipment).observaciones" />
              <FormField label="Servicio" :for-id="`plan-service-${equipment.id}`" class="sm:col-span-2"><select :id="`plan-service-${equipment.id}`" v-model="stateFor(equipment).serviceId" name="tipo_servicio_id" required :class="fieldClass" @change="changePlanService(equipment)"><option v-for="service in data.catalogs.serviceTypes" :key="service.id" :value="String(service.id)">{{ service.name }}</option></select></FormField>
              <p v-if="selectedPlanDefault(equipment)" class="rounded-lg bg-success-subtle p-3 text-sm font-semibold text-success-strong sm:col-span-2">
                {{ selectedPlanDefault(equipment).templateName }} · intervalos precargados
              </p>
              <template v-if="equipment.controlsKm"><FormField label="Cada km" :for-id="`plan-km-${equipment.id}`"><input :id="`plan-km-${equipment.id}`" v-model="stateFor(equipment).intervalo_km" type="number" min="1" name="intervalo_km" :class="fieldClass" /></FormField><FormField label="Avisar antes (km)" :for-id="`plan-km-warning-${equipment.id}`"><input :id="`plan-km-warning-${equipment.id}`" v-model="stateFor(equipment).anticipacion_km" type="number" min="0" name="anticipacion_km" :class="fieldClass" /></FormField></template>
              <template v-if="equipment.controlsHours"><FormField label="Cada horas" :for-id="`plan-hours-${equipment.id}`"><input :id="`plan-hours-${equipment.id}`" v-model="stateFor(equipment).intervalo_horas" type="number" min="0.1" step="0.1" name="intervalo_horas" :class="fieldClass" /></FormField><FormField label="Avisar antes (h)" :for-id="`plan-hours-warning-${equipment.id}`"><input :id="`plan-hours-warning-${equipment.id}`" v-model="stateFor(equipment).anticipacion_horas" type="number" min="0" step="0.1" name="anticipacion_horas" :class="fieldClass" /></FormField></template>
              <FormField label="Cada días" :for-id="`plan-days-${equipment.id}`"><input :id="`plan-days-${equipment.id}`" v-model="stateFor(equipment).intervalo_dias" type="number" min="1" name="intervalo_dias" :class="fieldClass" /></FormField><FormField label="Avisar antes (días)" :for-id="`plan-days-warning-${equipment.id}`"><input :id="`plan-days-warning-${equipment.id}`" v-model="stateFor(equipment).anticipacion_dias" type="number" min="0" name="anticipacion_dias" :class="fieldClass" /></FormField><button type="submit" :class="primaryButton">Crear plan</button>
            </form>
          </details>
        </article>
      </div>
      <PaginationBar :pagination="data.pagination.equipments" />
    </PanelCard>

    <PanelCard title="2. Vencimientos y avisos" class="mb-6">
      <template v-if="data.can.detectDue" #header-actions><form method="post" :action="data.routes.detectDue"><CsrfInput :csrf="data.csrf" /><button type="submit" class="inline-flex min-h-10 items-center rounded-lg bg-accent px-3.5 py-2 text-sm font-semibold text-accent-foreground hover:bg-accent-hover"><BoltIcon class="mr-2 size-5" aria-hidden="true" />Detectar vencidos</button></form></template>
      <h3 class="mb-3 text-sm font-bold uppercase tracking-wide text-ink-muted">Planes activos</h3>
      <EmptyState v-if="data.plans.length === 0" title="No hay planes activos" />
      <div v-else class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3"><article v-for="plan in data.plans" :key="plan.id" class="rounded-xl border border-border p-4"><div class="flex items-start justify-between gap-3"><strong class="text-ink">{{ plan.equipmentCode }}</strong><StatusBadge :status="plan.computedState || 'SIN_DATOS'" /></div><p class="mt-2 text-sm text-ink">{{ plan.serviceName }}</p><p class="mt-2 text-xs text-ink-muted">Próximo: <span v-if="plan.nextKm !== null">{{ plan.nextKm }} km </span><span v-if="plan.nextHours !== null">{{ plan.nextHours }} h </span><span v-if="plan.nextDate !== null">{{ plan.nextDate }}</span></p></article></div>

      <PaginationBar :pagination="data.pagination.plans" />

      <h3 class="mb-3 mt-7 text-sm font-bold uppercase tracking-wide text-ink-muted">Avisos pendientes</h3>
      <EmptyState v-if="data.notices.length === 0" title="No hay avisos vencidos pendientes" />
      <ul v-else class="space-y-3"><li v-for="notice in data.notices" :key="notice.id" class="flex flex-col justify-between gap-4 rounded-xl border border-danger/20 bg-danger-subtle/40 p-4 lg:flex-row lg:items-center"><div><strong class="text-ink">{{ notice.equipmentCode }} · {{ notice.serviceName }}</strong><p class="mt-1 text-sm text-danger-strong">Vencido por {{ notice.triggerCriteria }}</p></div><form v-if="data.can.generateOrder" method="post" :action="notice.generateOrderUrl" class="flex flex-col gap-2 sm:flex-row"><CsrfInput :csrf="data.csrf" /><label class="sr-only" :for="`notice-owner-${notice.id}`">Responsable</label><select :id="`notice-owner-${notice.id}`" name="responsable_usuario_id" :class="fieldClass"><option v-for="user in data.catalogs.users" :key="user.id" :value="user.id">{{ user.name }}</option></select><button type="submit" :class="primaryButton">Generar OT</button></form></li></ul>
      <PaginationBar :pagination="data.pagination.notices" />
    </PanelCard>

    <PanelCard title="3. Órdenes de trabajo" class="mb-6">
      <EmptyState v-if="data.orders.length === 0" title="Todavía no hay órdenes" description="Las órdenes generadas desde avisos aparecerán en esta sección." />
      <div v-else class="grid gap-4 xl:grid-cols-2"><article v-for="order in data.orders" :key="order.id" class="rounded-xl border border-border p-5"><div class="flex items-start justify-between gap-3"><strong class="text-ink">{{ order.number }} · {{ order.equipmentCode }}</strong><StatusBadge :status="order.status" /></div><p class="mt-2 text-sm text-ink-muted">{{ order.serviceName || 'Servicio preventivo' }} · Responsable: {{ order.ownerName || 'Sin asignar' }}</p><ul v-if="order.tasks.length" class="mt-3 space-y-1 text-sm text-ink"><li v-for="task in order.tasks" :key="task.id">{{ task.description }} <span class="text-ink-muted">({{ task.status }})</span></li></ul><form v-if="order.status === 'EMITIDA' && data.can.editOrder" method="post" :action="order.startUrl" class="mt-4"><CsrfInput :csrf="data.csrf" /><button type="submit" :class="secondaryButton">Iniciar orden</button></form><form v-else-if="order.status === 'EN_PROCESO' && data.can.closeOrder" method="post" :action="order.closeUrl" class="mt-5 grid gap-3 border-t border-border-subtle pt-5 sm:grid-cols-3"><CsrfInput :csrf="data.csrf" /><FormField label="Trabajo realizado" :for-id="`order-work-${order.id}`" class="sm:col-span-3"><textarea :id="`order-work-${order.id}`" name="trabajo_realizado" rows="2" required :class="fieldClass"></textarea></FormField><FormField label="Fecha servicio" :for-id="`order-date-${order.id}`"><input :id="`order-date-${order.id}`" type="date" name="fecha_servicio" required :value="today()" :class="fieldClass" /></FormField><FormField label="Km salida" :for-id="`order-km-${order.id}`"><input :id="`order-km-${order.id}`" type="number" min="0" name="km_salida" :class="fieldClass" /></FormField><FormField label="Horas salida" :for-id="`order-hours-${order.id}`"><input :id="`order-hours-${order.id}`" type="number" min="0" step="0.1" name="horas_salida" :class="fieldClass" /></FormField><button type="submit" :class="`${primaryButton} sm:col-span-3 sm:justify-self-start`">Cerrar y recalcular</button></form></article></div>
      <PaginationBar :pagination="data.pagination.orders" />
    </PanelCard>

    <PanelCard title="Historial reciente de lecturas" flush>
      <EmptyState v-if="data.readings.length === 0" title="Sin lecturas registradas" />
      <div v-else class="overflow-x-auto"><table class="w-full min-w-[40rem] text-left text-sm"><thead class="bg-surface-subtle text-xs uppercase tracking-wide text-ink-muted"><tr><th class="px-6 py-3">Equipo</th><th class="px-6 py-3">Fecha</th><th class="px-6 py-3">Km</th><th class="px-6 py-3">Horas</th><th class="px-6 py-3">Origen</th></tr></thead><tbody class="divide-y divide-border-subtle"><tr v-for="reading in data.readings" :key="reading.id"><td class="px-6 py-4 font-semibold text-ink">{{ reading.equipmentCode }}</td><td class="px-6 py-4 text-ink-muted">{{ reading.recordedAt }}</td><td class="px-6 py-4">{{ reading.kilometers ?? '—' }}</td><td class="px-6 py-4">{{ reading.hours ?? '—' }}</td><td class="px-6 py-4 text-ink-muted">{{ reading.origin }}</td></tr></tbody></table></div>
      <PaginationBar :pagination="data.pagination.readings" />
    </PanelCard>
  </div>
</template>
