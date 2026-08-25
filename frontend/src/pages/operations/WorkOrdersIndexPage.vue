<script setup>
import { computed, reactive, ref } from 'vue'
import { ArrowPathIcon, ChevronDownIcon, MagnifyingGlassIcon, PrinterIcon, WrenchScrewdriverIcon } from '@heroicons/vue/24/outline'
import CsrfInput from './components/CsrfInput.vue'
import EmptyState from './components/EmptyState.vue'
import PageHeading from './components/PageHeading.vue'
import StatusBadge from './components/StatusBadge.vue'
import WorkOrderClosureModal from './components/WorkOrderClosureModal.vue'
import { fieldClass, primaryButton, secondaryButton } from './helpers.js'

const props = defineProps({ data: { type: Object, required: true } })
const closeForms = reactive({})
const activeCloseOrder = ref(null)
const expandedOrders = ref([])

const kpiCards = computed(() => [
  { label: 'OT abiertas', value: props.data.kpis.open ?? 0, href: props.data.routes.index },
  { label: 'Emitidas', value: props.data.kpis.issued ?? 0, href: `${props.data.routes.index}?estado=EMITIDA` },
  { label: 'En proceso', value: props.data.kpis.inProgress ?? 0, href: `${props.data.routes.index}?estado=EN_PROCESO` },
  { label: 'Esperando repuestos', value: props.data.kpis.waitingParts ?? 0, href: `${props.data.routes.index}?estado=ESPERA_REPUESTOS` },
  { label: 'Demoradas', value: props.data.kpis.delayed ?? 0, href: `${props.data.routes.index}?atencion=delayed` },
  { label: 'Finalizadas hoy', value: props.data.kpis.finishedToday ?? 0, href: `${props.data.routes.index}?estado=FINALIZADA` },
])

const formatEntry = (order) => {
  const values = []
  if (order.entryKm !== null) values.push(`${Number(order.entryKm).toLocaleString('es-AR')} km`)
  if (order.entryHours !== null && order.entryHours !== '') values.push(`${order.entryHours} h`)
  return values.length ? values.join(' · ') : 'Sin lectura de ingreso'
}
const formatMoney = (value) => Number(value ?? 0).toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const isExpanded = (orderId) => expandedOrders.value.includes(orderId)
const toggleDetail = (orderId) => {
  expandedOrders.value = isExpanded(orderId)
    ? expandedOrders.value.filter((id) => id !== orderId)
    : [...expandedOrders.value, orderId]
}
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
    if (!closeForms[order.id].tasks[task.id]) closeForms[order.id].tasks[task.id] = { resultado: '', detalle: '' }
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
for (const order of props.data.orders ?? []) closeStateFor(order)
</script>

<template>
  <div>
    <PageHeading eyebrow="Taller" title="Órdenes de trabajo" description="Buscá, priorizá y retomá cualquier OT desde un único lugar.">
      <template #actions><a :href="data.routes.maintenance" :class="secondaryButton">Volver a Mantenimiento</a></template>
    </PageHeading>

    <section aria-label="Indicadores de órdenes" class="mb-6 grid gap-3 sm:grid-cols-2 xl:grid-cols-6">
      <a v-for="card in kpiCards" :key="card.label" :href="card.href" class="rounded-xl border border-border bg-surface-raised p-4 transition hover:border-primary/40 hover:shadow-sm">
        <p class="text-xs font-bold uppercase tracking-wide text-ink-muted">{{ card.label }}</p>
        <p class="mt-2 text-3xl font-bold text-ink">{{ card.value }}</p>
      </a>
    </section>

    <form method="get" :action="data.routes.index" class="mb-6 grid gap-3 rounded-xl border border-border bg-surface-raised p-4 lg:grid-cols-[minmax(16rem,1.6fr)_repeat(3,minmax(10rem,1fr))_auto]">
      <label class="relative"><span class="sr-only">Buscar OT</span><MagnifyingGlassIcon class="pointer-events-none absolute left-3 top-3 size-5 text-ink-subtle" /><input name="q" type="search" :value="data.filters.q" placeholder="Número, equipo o patente" :class="`${fieldClass} pl-10`" /></label>
      <select name="estado" :value="data.filters.status" :class="fieldClass"><option value="">Todos los estados</option><option value="EMITIDA">Emitida</option><option value="EN_PROCESO">En proceso</option><option value="ESPERA_REPUESTOS">Espera repuestos</option><option value="FINALIZADA">Finalizada</option><option value="CANCELADA">Cancelada</option></select>
      <select name="sucursal_id" :value="data.filters.branchId" :class="fieldClass"><option value="">Todas las sucursales</option><option v-for="branch in data.branches" :key="branch.id" :value="branch.id">{{ branch.name }}</option></select>
      <select name="responsable_id" :value="data.filters.ownerId" :class="fieldClass"><option value="">Todos los responsables</option><option v-for="owner in data.owners" :key="owner.id" :value="owner.id">{{ owner.name }}</option></select>
      <button type="submit" :class="primaryButton">Filtrar</button>
    </form>

    <div class="mb-3 flex items-end justify-between gap-3">
      <div><h2 class="text-lg font-bold text-ink">Órdenes que requieren atención</h2><p class="text-sm text-ink-muted">{{ data.pagination.total }} OT encontradas · demorada desde {{ data.delayDays }} días abierta.</p></div>
    </div>

    <EmptyState v-if="data.orders.length === 0" title="No se encontraron órdenes" description="Probá cambiar los filtros de búsqueda." />
    <div v-else class="space-y-3">
      <article v-for="order in data.orders" :id="`orden-${order.id}`" :key="order.id" class="scroll-mt-24 rounded-xl border border-border bg-surface-raised p-4 sm:p-5">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
          <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2"><h3 class="font-bold text-ink">{{ order.number }} · {{ order.equipmentCode }}</h3><StatusBadge :status="order.status" /><span v-if="order.delayed" class="rounded-full bg-danger-subtle px-2 py-1 text-xs font-bold text-danger-strong">DEMORADA</span></div>
            <p class="mt-1 text-sm text-ink-muted"><span v-if="order.plate">{{ order.plate }} · </span>{{ order.serviceName }} · {{ order.branchName }}</p>
            <dl class="mt-3 flex flex-wrap gap-x-5 gap-y-2 text-sm"><div><dt class="inline text-ink-muted">Prioridad: </dt><dd class="inline font-semibold text-ink">{{ order.priority }}</dd></div><div><dt class="inline text-ink-muted">Responsable: </dt><dd class="inline font-semibold text-ink">{{ order.ownerName }}</dd></div><div><dt class="inline text-ink-muted">Abierta: </dt><dd class="inline font-semibold text-ink">{{ order.openedAt }} · hace {{ order.ageDays }} días</dd></div><div><dt class="inline text-ink-muted">Ingreso: </dt><dd class="inline font-semibold text-ink">{{ formatEntry(order) }}</dd></div></dl>
          </div>
          <div class="flex flex-wrap gap-2 lg:justify-end">
            <button type="button" :class="secondaryButton" :aria-expanded="isExpanded(order.id)" :aria-controls="`detalle-orden-${order.id}`" @click="toggleDetail(order.id)"><ChevronDownIcon class="mr-2 size-4 transition" :class="isExpanded(order.id) ? 'rotate-180' : ''" />{{ isExpanded(order.id) ? 'Ocultar detalle' : 'Ver detalle' }}</button>
            <a :href="order.routes.print" target="_blank" rel="noopener" :class="secondaryButton"><PrinterIcon class="mr-2 size-4" />Imprimir</a>
            <form v-if="order.status === 'EMITIDA' && data.can.editOrder" method="post" :action="order.routes.start"><CsrfInput :csrf="data.csrf" /><button type="submit" :class="primaryButton">Iniciar</button></form>
            <form v-if="order.status === 'ESPERA_REPUESTOS' && data.can.editOrder" method="post" :action="order.routes.resume"><CsrfInput :csrf="data.csrf" /><button type="submit" :class="primaryButton"><ArrowPathIcon class="mr-2 size-4" />Reanudar</button></form>
            <button v-if="order.status === 'EN_PROCESO' && data.can.closeOrder" type="button" :class="primaryButton" aria-haspopup="dialog" @click="openCloseModal(order)"><WrenchScrewdriverIcon class="mr-2 size-4" />Cerrar</button>
          </div>
        </div>

        <div v-if="isExpanded(order.id)" :id="`detalle-orden-${order.id}`" class="mt-4 grid gap-4 border-t border-border-subtle pt-4 lg:grid-cols-2" data-testid="work-order-detail">
          <section class="rounded-xl bg-surface-subtle p-4">
            <h4 class="font-bold text-ink">Detalle de la intervención</h4>
            <dl class="mt-3 grid gap-2 text-sm">
              <div><dt class="inline text-ink-muted">Origen: </dt><dd class="inline font-semibold text-ink">{{ order.origin }}</dd></div>
              <div><dt class="inline text-ink-muted">Inicio: </dt><dd class="inline font-semibold text-ink">{{ order.startedAt || 'Sin iniciar' }}</dd></div>
              <div><dt class="inline text-ink-muted">Finalización: </dt><dd class="inline font-semibold text-ink">{{ order.finishedAt || 'Pendiente' }}</dd></div>
              <div v-if="order.diagnosis"><dt class="inline text-ink-muted">Diagnóstico: </dt><dd class="inline text-ink">{{ order.diagnosis }}</dd></div>
              <div v-if="order.notes"><dt class="inline text-ink-muted">Observaciones: </dt><dd class="inline text-ink">{{ order.notes }}</dd></div>
            </dl>
          </section>
          <section class="rounded-xl bg-surface-subtle p-4">
            <h4 class="font-bold text-ink">Tareas y costos</h4>
            <ul v-if="order.tasks.length" class="mt-3 space-y-2 text-sm"><li v-for="task in order.tasks" :key="task.id"><strong class="text-ink">{{ task.description }}</strong><span class="text-ink-muted"> · {{ task.status }}</span><p v-if="task.workPerformed" class="mt-1 text-ink-muted">{{ task.workPerformed }}</p></li></ul>
            <p v-else class="mt-3 text-sm text-ink-muted">Esta orden no tiene tareas preventivas asociadas.</p>
            <p class="mt-4 border-t border-border-subtle pt-3 text-sm text-ink-muted">Costos: mano de obra $ {{ formatMoney(order.costs.labor) }} · repuestos $ {{ formatMoney(order.costs.parts) }} · otros $ {{ formatMoney(order.costs.other) }}</p>
            <p class="mt-1 font-bold text-ink">Total: $ {{ formatMoney(order.costs.total) }}</p>
          </section>
        </div>
      </article>
    </div>

    <nav v-if="data.pagination.totalPages > 1" class="mt-6 flex items-center justify-between gap-3" aria-label="Paginación de órdenes"><a v-if="data.pagination.previousUrl" :href="data.pagination.previousUrl" :class="secondaryButton">Anterior</a><span v-else></span><span class="text-sm text-ink-muted">Página {{ data.pagination.page }} de {{ data.pagination.totalPages }}</span><a v-if="data.pagination.nextUrl" :href="data.pagination.nextUrl" :class="secondaryButton">Siguiente</a><span v-else></span></nav>

    <WorkOrderClosureModal
      v-if="activeCloseOrder"
      :order="{ ...activeCloseOrder, closeUrl: activeCloseOrder.routes.close }"
      :csrf="data.csrf"
      :form-state="closeStateFor(activeCloseOrder)"
      @close="closeCloseModal"
      @update:form-state="updateCloseForm"
    />
  </div>
</template>
