<script setup>
import * as uiLabels from '../../ui/labels.js'
import * as format from '../../ui/format.js'
import { computed, reactive, ref } from 'vue'
import { groupMyWork, isMyWorkView } from '../../ui/fieldShortcuts.js'
import { ArrowPathIcon, ChevronDownIcon, DocumentArrowUpIcon, MagnifyingGlassIcon, PlusIcon, PrinterIcon, WrenchScrewdriverIcon } from '@heroicons/vue/24/outline'
import CsrfInput from './components/CsrfInput.vue'
import CorrectiveWorkRegistrationModal from './components/CorrectiveWorkRegistrationModal.vue'
import EmptyState from './components/EmptyState.vue'
import FilterChip from '../../components/ui/FilterChip.vue'
import PageHeading from './components/PageHeading.vue'
import StatusBadge from './components/StatusBadge.vue'
import WorkOrderClosureModal from './components/WorkOrderClosureModal.vue'
import { fieldClass, primaryButton, secondaryButton } from './helpers.js'

const props = defineProps({ data: { type: Object, required: true } })
const closeForms = reactive({})
const activeCloseOrder = ref(null)
const activeCancelOrder = ref(null)
const correctiveModalOpen = ref(false)
const expandedOrders = ref([])

const filterDraft = reactive({
  q: props.data.filters?.q ?? '',
  status: props.data.filters?.status ?? '',
  branchId: props.data.filters?.branchId ?? '',
  ownerId: props.data.filters?.ownerId ?? '',
})
const filterHasValue = (value) => value !== undefined && value !== null && String(value).trim() !== ''
const secondaryFilters = computed(() => [props.data.filters?.status, props.data.filters?.branchId, props.data.filters?.ownerId])
const hasActiveFilters = computed(() => [props.data.filters?.q, props.data.filters?.attention, ...secondaryFilters.value].some(filterHasValue))
const filtersExpanded = ref(secondaryFilters.value.some(filterHasValue))
const attentionFilterActive = computed(() => props.data.filters?.attention === 'delayed')
const ordersHeading = computed(() => attentionFilterActive.value ? 'Órdenes que requieren atención' : 'Órdenes registradas')
const ordersSummary = computed(() => {
  const total = Number(props.data.pagination?.total ?? 0)
  const countLabel = `${total} OT ${total === 1 ? 'encontrada' : 'encontradas'}`
  return attentionFilterActive.value
    ? `${countLabel} · abiertas desde ${props.data.delayDays} días o más.`
    : countLabel
})

// Los contadores filtran por URL; la píldora activa es la que coincide con el filtro aplicado.
const kpiCards = computed(() => {
  const status = props.data.filters?.status ?? ''
  const attention = props.data.filters?.attention ?? ''
  const card = (label, value, query, active) => ({ label, value, href: `${props.data.routes.index}${query}`, active })
  return [
    card('Todas', null, '', !status && !attention),
    card('OT abiertas', props.data.kpis.open ?? 0, '?atencion=open', attention === 'open'),
    card('Emitidas', props.data.kpis.issued ?? 0, '?estado=EMITIDA', status === 'EMITIDA'),
    card('En proceso', props.data.kpis.inProgress ?? 0, '?estado=EN_PROCESO', status === 'EN_PROCESO'),
    card('Esperando repuestos', props.data.kpis.waitingParts ?? 0, '?estado=ESPERA_REPUESTOS', status === 'ESPERA_REPUESTOS'),
    card('Demoradas', props.data.kpis.delayed ?? 0, '?atencion=delayed', attention === 'delayed'),
    card('Finalizadas hoy', props.data.kpis.finishedToday ?? 0, '?atencion=finished-today', attention === 'finished-today'),
  ]
})

const formatEntry = (order) => {
  const values = []
  if (order.entryKm !== null) values.push(`${format.formatNumber(order.entryKm)} km`)
  if (order.entryHours !== null && order.entryHours !== '') values.push(`${format.formatNumber(order.entryHours, 1)} h`)
  return values.length ? values.join(' · ') : 'Sin lectura de ingreso'
}
const formatMoney = (value) => format.formatMoney(value ?? 0)
const formatRate = (value) => format.formatMoney(value ?? 0, { cents: true })
const formatCurrencyMoney = (value, currency) => format.formatMoney(value ?? 0, { currency })
const hasForeignHistoricalCost = (order) => {
  const historical = order.historicalCost
  return Boolean(historical?.currency && historical.currency !== 'ARS' && historical.originalAmount !== null)
}
const formatExchangeRateDate = (value) => {
  if (!value) return ''
  const [year, month, day] = String(value).split('-')
  return year && month && day ? `${day}/${month}/${year}` : value
}
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
      repuestos: [],
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
const openCancelModal = (order) => { activeCancelOrder.value = order }
const closeCancelModal = () => { activeCancelOrder.value = null }
const updateCloseForm = (value) => {
  if (activeCloseOrder.value) closeForms[activeCloseOrder.value.id] = value
}
for (const order of props.data.orders ?? []) closeStateFor(order)

// "Mi trabajo" (spec §6.2) is this list filtered by the server to my orders, grouped by
// what to do next. The filters and totals belong to the full list, so they hide here.
const myWork = isMyWorkView(window.location.search)
const sections = computed(() => (myWork ? groupMyWork(props.data.orders) : [{ title: '', orders: props.data.orders }]))
</script>

<template>
  <div>
    <PageHeading
      eyebrow="Taller"
      :title="myWork ? 'Mi trabajo' : 'Órdenes de trabajo'"
      :description="myWork ? 'Las órdenes asignadas a vos, en el orden en que conviene atenderlas.' : 'Consultá las órdenes del taller por equipo, estado, sucursal o responsable.'"
    >
      <template v-if="data.can.editOrder" #actions>
        <div class="flex w-full flex-col gap-2 min-[360px]:flex-row sm:w-auto sm:flex-wrap lg:justify-end">
          <a :href="data.routes.importDocument" :class="`${secondaryButton} min-w-0 flex-1 text-center sm:flex-none`"><DocumentArrowUpIcon class="size-4 shrink-0" aria-hidden="true" /><span>Importar orden de taller</span></a>
          <button type="button" :class="`${primaryButton} min-w-0 flex-1 text-center sm:flex-none`" aria-haspopup="dialog" @click="correctiveModalOpen = true"><PlusIcon class="size-4 shrink-0" aria-hidden="true" /><span>Registrar correctivo</span></button>
        </div>
      </template>
    </PageHeading>

    <!-- Contadores como píldoras que filtran; en el celular se desplazan de costado. -->
    <nav v-if="!myWork" aria-label="Indicadores de órdenes" class="-mx-1 mb-4 flex gap-2 overflow-x-auto px-1 pb-2 pt-1 [scrollbar-width:none]">
      <FilterChip v-for="card in kpiCards" :key="card.label" :label="card.label" :count="card.value" :href="card.href" :active="card.active" />
    </nav>

    <form v-if="!myWork" method="get" :action="data.routes.index" aria-label="Filtrar órdenes" class="mb-6 grid grid-cols-[auto_minmax(0,1fr)] gap-2 rounded-card bg-surface-raised p-3 shadow-card sm:gap-3 sm:p-5 md:grid-cols-2 lg:grid-cols-4 xl:grid-cols-[minmax(0,1.5fr)_repeat(3,minmax(0,1fr))_auto]">
      <label class="col-span-2 min-w-0 xl:col-span-1">
        <span class="mb-1 block text-xs font-semibold text-ink">Buscar OT</span>
        <span class="relative block">
          <MagnifyingGlassIcon class="pointer-events-none absolute left-3 top-3 size-5 text-ink-subtle" aria-hidden="true" />
          <input v-model="filterDraft.q" name="q" type="search" placeholder="Número, equipo o patente" :class="`${fieldClass} pl-10`" />
        </span>
      </label>
      <button type="button" :class="`${secondaryButton} justify-self-start md:hidden`" :aria-expanded="filtersExpanded" aria-controls="work-order-extra-filters" @click="filtersExpanded = !filtersExpanded">
        Más filtros <ChevronDownIcon class="size-4 shrink-0" :class="{ 'rotate-180': filtersExpanded }" aria-hidden="true" />
      </button>
      <div id="work-order-extra-filters" class="col-span-2 grid-cols-2 gap-3 pt-1 md:contents" :class="filtersExpanded ? 'grid' : 'hidden'">
        <label class="min-w-0">
          <span class="mb-1 block text-xs font-semibold text-ink">Estado</span>
          <select v-model="filterDraft.status" name="estado" :class="fieldClass">
            <option value="">Todos</option><option value="EMITIDA">Emitida</option><option value="EN_PROCESO">En proceso</option><option value="ESPERA_REPUESTOS">Espera repuestos</option><option value="FINALIZADA">Finalizada</option><option value="CANCELADA">Cancelada</option>
          </select>
        </label>
        <label class="min-w-0">
          <span class="mb-1 block text-xs font-semibold text-ink">Sucursal</span>
          <select v-model="filterDraft.branchId" name="sucursal_id" :class="fieldClass">
            <option value="">Todas</option><option v-for="branch in data.branches" :key="branch.id" :value="branch.id">{{ branch.name }}</option>
          </select>
        </label>
        <label class="col-span-2 min-w-0 md:col-span-1">
          <span class="mb-1 block text-xs font-semibold text-ink">Responsable</span>
          <select v-model="filterDraft.ownerId" name="responsable_id" :class="fieldClass">
            <option value="">Todos</option><option v-for="owner in data.owners" :key="owner.id" :value="owner.id">{{ owner.name }}</option>
          </select>
        </label>
      </div>
      <div class="flex flex-wrap items-center justify-end gap-x-2 gap-y-1 self-end md:col-span-1 md:justify-start lg:col-span-3 xl:col-span-1" :class="{ 'col-span-2': filtersExpanded }">
        <a v-if="hasActiveFilters" :href="data.routes.index" class="inline-flex min-h-11 items-center rounded-sm text-xs font-semibold text-primary underline underline-offset-4 hover:text-primary-hover focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-border-focus sm:px-2 sm:text-sm">Limpiar filtros</a>
        <button type="submit" :class="primaryButton">Filtrar</button>
      </div>
    </form>

    <div class="mb-3 flex flex-wrap items-center justify-between gap-x-2 gap-y-1">
      <div><h2 class="text-lg font-bold text-ink">{{ ordersHeading }}</h2><p class="text-sm text-ink-muted">{{ ordersSummary }}</p></div>
    </div>

    <EmptyState v-if="data.orders.length === 0 && myWork" title="No tenés órdenes asignadas" description="Cuando te asignen una orden de trabajo va a aparecer acá." />
    <EmptyState v-else-if="data.orders.length === 0" title="No se encontraron órdenes" description="Probá cambiar los filtros de búsqueda o registrá el correctivo si todavía no existe." />
    <div v-else class="space-y-8 rounded-card bg-surface-raised p-2 shadow-card sm:p-3">
      <section v-for="section in sections" :key="section.title || 'todas'" :aria-label="section.title || undefined">
      <h2 v-if="section.title" class="mb-3 px-2 pt-2 text-lg font-bold text-ink">{{ section.title }} <span class="text-sm font-semibold text-ink-muted">({{ section.orders.length }})</span></h2>
      <div class="space-y-2">
      <article v-for="order in section.orders" :id="`orden-${order.id}`" :key="order.id" class="scroll-mt-24 rounded-[18px] bg-surface-subtle p-4 transition-colors hover:bg-surface-muted/60 sm:p-5">
        <div class="grid gap-4 2xl:grid-cols-[minmax(0,1fr)_auto] 2xl:items-center">
          <div class="min-w-0 [overflow-wrap:anywhere]">
            <div class="flex flex-wrap items-center gap-2"><h3 class="font-bold text-ink">{{ order.number }} · {{ order.equipmentCode }}</h3><StatusBadge :status="order.status" /><span v-if="order.delayed" class="inline-flex h-7 items-center rounded-pill bg-danger-subtle px-2.5 text-xs font-semibold text-danger-strong">Demorada</span></div>
            <p class="mt-1 text-sm text-ink-muted"><span v-if="order.plate">{{ order.plate }} · </span>{{ order.serviceName }} · {{ order.branchName }}</p>
            <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-2.5 text-sm sm:grid-cols-4">
              <div><dt class="text-xs text-ink-muted">Prioridad:</dt><dd class="font-semibold text-ink">{{ uiLabels.humanizeCode(order.priority) }}</dd></div>
              <div><dt class="text-xs text-ink-muted">Responsable:</dt><dd class="font-semibold text-ink">{{ order.ownerName }}</dd></div>
              <div><dt class="text-xs text-ink-muted">Fecha de apertura:</dt><dd class="font-semibold tabular-nums text-ink">{{ format.formatDateTime(order.openedAt) }} · {{ format.formatRelativeDays(order.openedAt) }}</dd></div>
              <div><dt class="text-xs text-ink-muted">Ingreso:</dt><dd class="font-semibold tabular-nums text-ink">{{ formatEntry(order) }}</dd></div>
            </dl>
          </div>
          <div class="flex min-w-0 flex-wrap gap-2 pt-1 2xl:max-w-[26rem] 2xl:justify-end 2xl:border-0 2xl:pt-0">
            <form v-if="order.status === 'EMITIDA' && data.can.editOrder" method="post" :action="order.routes.start"><CsrfInput :csrf="data.csrf" /><button type="submit" :class="primaryButton">Iniciar</button></form>
            <form v-if="order.status === 'ESPERA_REPUESTOS' && data.can.editOrder" method="post" :action="order.routes.resume"><CsrfInput :csrf="data.csrf" /><button type="submit" :class="primaryButton"><ArrowPathIcon class="size-4" aria-hidden="true" />Reanudar</button></form>
            <button v-if="order.status === 'EN_PROCESO' && data.can.closeOrder" type="button" :class="primaryButton" aria-haspopup="dialog" @click="openCloseModal(order)"><WrenchScrewdriverIcon class="size-4" aria-hidden="true" />Cerrar</button>
            <button type="button" :class="secondaryButton" :aria-expanded="isExpanded(order.id)" :aria-controls="`detalle-orden-${order.id}`" @click="toggleDetail(order.id)"><ChevronDownIcon class="size-4 transition" :class="isExpanded(order.id) ? 'rotate-180' : ''" aria-hidden="true" />{{ isExpanded(order.id) ? 'Ocultar detalle' : 'Ver detalle' }}</button>
            <a :href="order.routes.print" target="_blank" rel="noopener" :class="secondaryButton"><PrinterIcon class="size-4" aria-hidden="true" />Imprimir</a>
            <button v-if="['EMITIDA', 'EN_PROCESO', 'ESPERA_REPUESTOS'].includes(order.status) && data.can.editOrder" type="button" :class="secondaryButton" aria-haspopup="dialog" @click="openCancelModal(order)">Anular</button>
          </div>
        </div>

        <div v-if="isExpanded(order.id)" :id="`detalle-orden-${order.id}`" class="mt-4 grid gap-4 border-t border-border-subtle pt-4 [overflow-wrap:anywhere] lg:grid-cols-2" data-testid="work-order-detail">
          <section class="rounded-xl bg-surface-subtle p-4">
            <h4 class="font-bold text-ink">Detalle de la intervención</h4>
            <dl class="mt-3 grid gap-2 text-sm">
              <div><dt class="inline text-ink-muted">Origen: </dt><dd class="inline font-semibold text-ink">{{ uiLabels.humanizeCode(order.origin) }}</dd></div>
              <div><dt class="inline text-ink-muted">Inicio: </dt><dd class="inline font-semibold text-ink">{{ format.formatDateTime(order.startedAt, 'Sin iniciar') }}</dd></div>
              <div><dt class="inline text-ink-muted">Finalización: </dt><dd class="inline font-semibold text-ink">{{ format.formatDateTime(order.finishedAt, 'Pendiente') }}</dd></div>
              <div v-if="order.diagnosis"><dt class="inline text-ink-muted">Diagnóstico: </dt><dd class="inline text-ink">{{ order.diagnosis }}</dd></div>
              <div v-if="order.notes"><dt class="inline text-ink-muted">Observaciones: </dt><dd class="inline text-ink">{{ order.notes }}</dd></div>
            </dl>
          </section>
          <section class="rounded-xl bg-surface-subtle p-4">
            <h4 class="font-bold text-ink">Tareas, repuestos y costos</h4>
            <ul v-if="order.tasks.length" class="mt-3 space-y-2 text-sm"><li v-for="task in order.tasks" :key="task.id"><strong class="text-ink">{{ task.description }}</strong><span class="text-ink-muted"> · {{ uiLabels.humanizeCode(task.status) }}</span><p v-if="task.workPerformed" class="mt-1 text-ink-muted">{{ task.workPerformed }}</p></li></ul>
            <p v-else class="mt-3 text-sm text-ink-muted">Esta orden no tiene tareas preventivas asociadas.</p>
            <ul v-if="order.parts?.length" class="mt-4 space-y-2 border-t border-border-subtle pt-3 text-sm"><li v-for="part in order.parts" :key="part.id" class="flex flex-wrap justify-between gap-2"><span><strong class="text-ink">{{ part.description }}</strong><span v-if="part.code" class="ml-2 text-xs text-ink-muted">{{ part.code }}</span><span class="ml-2 text-xs text-ink-muted">× {{ part.quantity }}</span><span v-if="part.supplierName" class="block text-xs text-ink-muted">{{ part.supplierName }}</span><span v-if="part.warrantyDate || part.warrantyKm || part.warrantyHours" class="block text-xs text-success-strong">Garantía: {{ format.formatDateTime(part.warrantyDate, 'sin fecha') }}<template v-if="part.warrantyKm"> · {{ format.formatNumber(part.warrantyKm) }} km</template><template v-if="part.warrantyHours"> · {{ format.formatNumber(part.warrantyHours, 1) }} h</template></span></span><span class="font-semibold text-ink">{{ formatMoney(part.quantity * part.unitPrice) }}</span></li></ul>
            <template v-if="hasForeignHistoricalCost(order)">
              <p class="mt-4 border-t border-border-subtle pt-3 text-sm text-ink-muted">Costos originales: mano de obra {{ formatCurrencyMoney(order.costs.labor, order.historicalCost.currency) }} · repuestos {{ formatCurrencyMoney(order.costs.parts, order.historicalCost.currency) }} · otros {{ formatCurrencyMoney(order.costs.other, order.historicalCost.currency) }}</p>
              <p class="mt-1 font-bold text-ink">Total original: {{ formatCurrencyMoney(order.historicalCost.originalAmount, order.historicalCost.currency) }}</p>
              <dl class="mt-3 grid gap-1 text-sm">
                <div v-if="order.historicalCost.exchangeRateArs !== null"><dt class="inline text-ink-muted">Tipo de cambio histórico: </dt><dd class="inline font-semibold text-ink">1 {{ order.historicalCost.currency }} = {{ formatRate(order.historicalCost.exchangeRateArs) }} ARS</dd></div>
                <div v-if="order.historicalCost.exchangeRateDate"><dt class="inline text-ink-muted">Fecha cotización: </dt><dd class="inline text-ink">{{ formatExchangeRateDate(order.historicalCost.exchangeRateDate) }}</dd></div>
                <div v-if="order.historicalCost.exchangeRateSource"><dt class="inline text-ink-muted">Origen cotización: </dt><dd class="inline text-ink">{{ order.historicalCost.exchangeRateSource }}</dd></div>
              </dl>
              <p v-if="order.historicalCost.amountArs !== null" class="mt-2 font-bold text-ink">Total histórico ARS: {{ formatMoney(order.historicalCost.amountArs) }}</p>
            </template>
            <template v-else>
              <p class="mt-4 border-t border-border-subtle pt-3 text-sm text-ink-muted">Costos: mano de obra {{ formatMoney(order.costs.labor) }} · repuestos {{ formatMoney(order.costs.parts) }} · otros {{ formatMoney(order.costs.other) }}</p>
              <p class="mt-1 font-bold text-ink">Total: {{ formatMoney(order.costs.total) }}</p>
            </template>
          </section>
        </div>
      </article>
      </div>
      </section>
    </div>

    <nav v-if="data.pagination.totalPages > 1" class="mt-6 grid grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-2" aria-label="Paginación de órdenes"><a v-if="data.pagination.previousUrl" :href="data.pagination.previousUrl" :class="secondaryButton">Anterior</a><span v-else></span><span class="text-center text-xs text-ink-muted sm:text-sm">Página {{ data.pagination.page }} de {{ data.pagination.totalPages }}</span><a v-if="data.pagination.nextUrl" :href="data.pagination.nextUrl" :class="secondaryButton">Siguiente</a><span v-else></span></nav>

    <Teleport to="body">
      <div v-if="activeCancelOrder" class="fixed inset-0 z-[9999] flex items-center justify-center bg-black/40 p-4" role="dialog" aria-modal="true" aria-labelledby="work-order-cancel-heading" data-testid="work-order-cancel-modal">
      <div class="max-h-full w-full max-w-lg overflow-y-auto rounded-2xl bg-surface-raised p-4 shadow-xl sm:p-6">
        <div class="flex items-start justify-between gap-4">
          <div class="min-w-0 [overflow-wrap:anywhere]">
            <h2 id="work-order-cancel-heading" class="text-xl font-bold text-ink">Anular {{ activeCancelOrder.number }}</h2>
            <p class="mt-2 text-sm text-ink-muted">La orden quedará cancelada y fuera del circuito operativo. No se registrarán trabajos, lecturas ni costos.</p>
          </div>
          <button type="button" :class="secondaryButton" aria-label="Cerrar" @click="closeCancelModal">×</button>
        </div>
        <form method="post" :action="`${data.routes.index}/${activeCancelOrder.id}/cancelar`" class="mt-5 space-y-4">
          <CsrfInput :csrf="data.csrf" />
          <label class="block">
            <span class="mb-1 block text-sm font-semibold text-ink">Motivo de anulación</span>
            <textarea name="motivo" required minlength="5" maxlength="255" rows="3" :class="fieldClass" placeholder="Ej.: Orden duplicada"></textarea>
          </label>
          <div class="flex flex-wrap justify-end gap-2">
            <button type="button" :class="secondaryButton" @click="closeCancelModal">Volver</button>
            <button type="submit" :class="primaryButton">Confirmar anulación</button>
          </div>
        </form>
      </div>
      </div>
    </Teleport>

    <CorrectiveWorkRegistrationModal v-if="correctiveModalOpen" :data="data" @close="correctiveModalOpen = false" />
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
