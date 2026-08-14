<script setup>
import { computed, ref } from 'vue'
import { ArrowDownTrayIcon, ChartBarSquareIcon, FunnelIcon } from '@heroicons/vue/24/outline'
import ReportMetricCard from './ReportMetricCard.vue'
import SemanticBarList from './SemanticBarList.vue'

const props = defineProps({
  data: { type: Object, required: true },
  urls: { type: Object, required: true },
})

const activeTab = ref('summary')
const tabs = [
  ['summary', 'Resumen'],
  ['costs', 'Costos'],
  ['quality', 'Calidad'],
  ['orders', 'Órdenes'],
]
const currency = new Intl.NumberFormat('es-AR', { style: 'currency', currency: 'ARS', maximumFractionDigits: 2 })
const number = new Intl.NumberFormat('es-AR')
const date = new Intl.DateTimeFormat('es-AR', { day: '2-digit', month: 'short', year: 'numeric' })

const money = (value) => currency.format(Number(value) || 0)
const dateLabel = (value) => {
  if (!value) return '—'
  const parsed = new Date(String(value).replace(' ', 'T'))
  return Number.isNaN(parsed.getTime()) ? '—' : date.format(parsed)
}
const statusLabel = (status) => ({
  BORRADOR: 'Borrador', EMITIDA: 'Emitida', EN_PROCESO: 'En proceso',
  EN_ESPERA_REPUESTOS: 'En espera', FINALIZADA: 'Finalizada', CANCELADA: 'Cancelada',
}[status] ?? status)
const statusTone = (status) => ({
  FINALIZADA: 'bg-success-subtle text-success-strong',
  CANCELADA: 'bg-danger-subtle text-danger-strong',
  EN_PROCESO: 'bg-info-subtle text-info-strong',
  EN_ESPERA_REPUESTOS: 'bg-warning-subtle text-warning-strong',
}[status] ?? 'bg-surface-muted text-ink-muted')

const metrics = computed(() => {
  const source = props.data.metrics
  return {
    totalCost: { ...source.totalCost, displayValue: source.totalCost.available ? money(source.totalCost.value) : '' },
    openOrders: { ...source.openOrders, displayValue: number.format(source.openOrders.value) },
    completedOrders: { ...source.completedOrders, displayValue: number.format(source.completedOrders.value) },
    downtimeHours: { ...source.downtimeHours, displayValue: source.downtimeHours.available ? `${source.downtimeHours.value} h` : '' },
    mttrHours: { ...source.mttrHours, displayValue: source.mttrHours.available ? `${source.mttrHours.value} h` : '' },
  }
})
const statusItems = computed(() => props.data.statusDistribution.map((item) => ({ ...item, label: statusLabel(item.status) })))
const costItems = computed(() => props.data.costsByEquipment.map((item) => ({ ...item, numericCost: Number(item.cost) || 0 })))
const evolutionItems = computed(() => props.data.evolution.map((item) => ({
  ...item,
  numericCost: Number(item.cost) || 0,
  label: props.data.evolutionGranularity === 'month' ? item.period : dateLabel(`${item.period} 00:00:00`),
})))
const branchLabel = computed(() => props.data.branches.find((branch) => Number(branch.id) === Number(props.data.filters.branchId))?.nombre ?? 'Todas las sucursales')
const filterSummary = computed(() => `${branchLabel.value} · ${dateLabel(props.data.filters.from)}–${dateLabel(props.data.filters.to)}`)

const queryUrl = (page) => {
  const params = new URLSearchParams()
  if (props.data.filters.branchId) params.set('sucursal_id', props.data.filters.branchId)
  params.set('desde', props.data.filters.from)
  params.set('hasta', props.data.filters.to)
  params.set('per_page', String(props.data.filters.perPage || 10))
  params.set('page', String(page))
  return `${props.urls.index}?${params.toString()}`
}
const pagination = computed(() => props.data.orders.pagination)
</script>

<template>
  <div>
    <header class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
      <div>
        <p class="flex items-center gap-2 text-sm font-semibold text-primary"><ChartBarSquareIcon class="size-5" aria-hidden="true" />Análisis operativo</p>
        <h1 class="mt-1 text-2xl font-bold tracking-tight text-ink sm:text-3xl">Reportes de mantenimiento</h1>
        <p class="mt-2 max-w-3xl text-sm leading-6 text-ink-muted">Una lectura clara del período, con el detalle disponible cuando lo necesitás.</p>
      </div>
      <a :href="urls.export" class="inline-flex min-h-11 items-center justify-center gap-2 self-start rounded-lg border border-border-strong bg-surface-raised px-4 py-2.5 text-sm font-semibold text-ink hover:bg-surface-muted sm:self-auto">
        <ArrowDownTrayIcon class="size-5" aria-hidden="true" />Exportar órdenes CSV
      </a>
    </header>

    <details class="group mt-6 rounded-xl border border-border bg-surface-raised">
      <summary class="flex min-h-14 cursor-pointer list-none items-center justify-between gap-3 px-4 py-3 marker:hidden sm:px-5">
        <span class="flex min-w-0 items-center gap-3"><FunnelIcon class="size-5 shrink-0 text-primary" aria-hidden="true" /><span class="min-w-0"><span class="block text-sm font-semibold text-ink">Filtros del reporte</span><span class="block truncate text-xs text-ink-muted">{{ filterSummary }}</span></span></span>
        <span class="text-sm font-semibold text-primary group-open:hidden">Editar</span><span class="hidden text-sm font-semibold text-primary group-open:inline">Cerrar</span>
      </summary>
      <form :action="urls.index" method="get" class="grid gap-4 border-t border-border-subtle p-4 sm:grid-cols-2 xl:grid-cols-[minmax(12rem,1fr)_repeat(3,minmax(9rem,0.7fr))_auto] xl:items-end">
        <label class="grid gap-1.5 text-sm font-semibold text-ink">Sucursal<select name="sucursal_id" class="min-h-11 rounded-lg border border-border-strong bg-white px-3 text-sm font-normal text-ink"><option value="">Todas las autorizadas</option><option v-for="branch in data.branches" :key="branch.id" :value="branch.id" :selected="Number(data.filters.branchId) === Number(branch.id)">{{ branch.nombre }}</option></select></label>
        <label class="grid gap-1.5 text-sm font-semibold text-ink">Desde<input type="date" name="desde" :value="data.filters.from" required class="min-h-11 rounded-lg border border-border-strong bg-white px-3 text-sm font-normal text-ink" /></label>
        <label class="grid gap-1.5 text-sm font-semibold text-ink">Hasta<input type="date" name="hasta" :value="data.filters.to" required class="min-h-11 rounded-lg border border-border-strong bg-white px-3 text-sm font-normal text-ink" /></label>
        <label class="grid gap-1.5 text-sm font-semibold text-ink">Resultados por página<select name="per_page" class="min-h-11 rounded-lg border border-border-strong bg-white px-3 text-sm font-normal text-ink"><option v-for="size in [5, 10, 25]" :key="size" :value="size" :selected="Number(data.filters.perPage) === size">{{ size }}</option></select></label>
        <button type="submit" class="min-h-11 rounded-lg bg-primary px-5 py-2.5 text-sm font-semibold text-primary-foreground hover:bg-primary-hover">Aplicar filtros</button>
      </form>
    </details>

    <div class="mt-6 overflow-x-auto border-b border-border" role="tablist" aria-label="Secciones del reporte">
      <div class="flex min-w-max gap-1">
        <button v-for="tab in tabs" :id="`report-tab-${tab[0]}`" :key="tab[0]" type="button" role="tab" :aria-selected="activeTab === tab[0]" :aria-controls="`report-panel-${tab[0]}`" class="min-h-11 border-b-2 px-4 py-2.5 text-sm font-semibold transition" :class="activeTab === tab[0] ? 'border-primary text-primary' : 'border-transparent text-ink-muted hover:border-border-strong hover:text-ink'" @click="activeTab = tab[0]">{{ tab[1] }}</button>
      </div>
    </div>

    <section v-if="activeTab === 'summary'" id="report-panel-summary" role="tabpanel" aria-labelledby="report-tab-summary" class="mt-6 space-y-6">
      <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
        <ReportMetricCard label="Costo total" :metric="metrics.totalCost" hint="OT finalizadas en el período" />
        <ReportMetricCard label="Órdenes abiertas" :metric="metrics.openOrders" hint="Estado actual en el alcance" tone="warning" />
        <ReportMetricCard label="Finalizadas" :metric="metrics.completedOrders" hint="Finalizadas en el período" tone="success" />
        <ReportMetricCard label="Detención" :metric="metrics.downtimeHours" hint="Sólo intervalos válidos" tone="danger" />
        <ReportMetricCard label="MTTR correctivo" :metric="metrics.mttrHours" hint="Promedio con muestra válida" tone="primary" />
      </div>
      <section aria-labelledby="status-title" class="rounded-xl border border-border bg-surface-raised p-5 sm:p-6">
        <h2 id="status-title" class="text-lg font-bold text-ink">Órdenes por estado</h2>
        <p class="mb-5 mt-1 text-sm text-ink-muted">Actividad abierta dentro del período seleccionado.</p>
        <SemanticBarList :items="statusItems" value-key="count" label-key="label" :value-formatter="number.format" />
      </section>
    </section>

    <section v-else-if="activeTab === 'costs'" id="report-panel-costs" role="tabpanel" aria-labelledby="report-tab-costs" class="mt-6 grid gap-6 xl:grid-cols-2">
      <section aria-labelledby="cost-title" class="rounded-xl border border-border bg-surface-raised p-5 sm:p-6">
        <h2 id="cost-title" class="text-lg font-bold text-ink">Costos por equipo</h2><p class="mb-5 mt-1 text-sm text-ink-muted">Hasta 10 equipos con OT finalizadas.</p>
        <SemanticBarList :items="costItems" value-key="numericCost" label-key="equipmentCode" :value-formatter="money" />
      </section>
      <section aria-labelledby="evolution-title" class="rounded-xl border border-border bg-surface-raised p-5 sm:p-6">
        <h2 id="evolution-title" class="text-lg font-bold text-ink">Evolución de costos</h2><p class="mb-5 mt-1 text-sm text-ink-muted">Agrupación {{ data.evolutionGranularity === 'day' ? 'diaria' : 'mensual' }} según finalización.</p>
        <SemanticBarList :items="evolutionItems" value-key="numericCost" label-key="label" :value-formatter="money" />
      </section>
    </section>

    <section v-else-if="activeTab === 'quality'" id="report-panel-quality" role="tabpanel" aria-labelledby="report-tab-quality" class="mt-6 rounded-xl border border-border bg-surface-raised p-5 sm:p-6">
      <h2 class="text-lg font-bold text-ink">Calidad y disponibilidad de datos</h2>
      <dl class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <div class="rounded-lg bg-surface-muted p-4"><dt class="text-xs font-semibold uppercase tracking-wide text-ink-muted">OT finalizadas</dt><dd class="mt-1 text-xl font-bold text-ink">{{ data.quality.completedOrders }}</dd></div>
        <div class="rounded-lg bg-surface-muted p-4"><dt class="text-xs font-semibold uppercase tracking-wide text-ink-muted">Detenciones válidas</dt><dd class="mt-1 text-xl font-bold text-success-strong">{{ data.quality.validDowntimeSamples }}</dd></div>
        <div class="rounded-lg bg-surface-muted p-4"><dt class="text-xs font-semibold uppercase tracking-wide text-ink-muted">Detenciones inválidas</dt><dd class="mt-1 text-xl font-bold text-danger-strong">{{ data.quality.invalidDowntimeSamples }}</dd></div>
        <div class="rounded-lg bg-surface-muted p-4"><dt class="text-xs font-semibold uppercase tracking-wide text-ink-muted">Muestra MTTR</dt><dd class="mt-1 text-xl font-bold text-ink">{{ data.quality.correctiveMttrSamples }}</dd></div>
      </dl>
      <ul class="mt-4 list-disc space-y-1 pl-5 text-sm leading-6 text-ink-muted"><li v-for="limitation in data.quality.limitations" :key="limitation">{{ limitation }}</li></ul>
    </section>

    <section v-else id="report-panel-orders" role="tabpanel" aria-labelledby="report-tab-orders" class="mt-6 overflow-hidden rounded-xl border border-border bg-surface-raised">
      <div class="border-b border-border px-5 py-4 sm:px-6"><h2 class="text-lg font-bold text-ink">Órdenes del período</h2><p class="mt-1 text-sm text-ink-muted">{{ data.orders.pagination.total }} resultados; listado paginado en servidor.</p></div>
      <div v-if="data.orders.items.length" class="overflow-x-auto">
        <table class="min-w-full divide-y divide-border text-left text-sm">
          <thead class="bg-surface-muted text-xs font-semibold uppercase tracking-wide text-ink-muted"><tr><th class="px-5 py-3">Orden</th><th class="px-5 py-3">Equipo</th><th class="px-5 py-3">Sucursal</th><th class="px-5 py-3">Apertura</th><th class="px-5 py-3">Estado</th><th class="px-5 py-3 text-right">Costo</th></tr></thead>
          <tbody class="divide-y divide-border-subtle"><tr v-for="order in data.orders.items" :key="order.id" class="hover:bg-surface-subtle"><td class="whitespace-nowrap px-5 py-4 font-semibold text-ink">{{ order.number }}</td><td class="whitespace-nowrap px-5 py-4 text-ink">{{ order.equipmentCode }}</td><td class="whitespace-nowrap px-5 py-4 text-ink-muted">{{ order.branchName }}</td><td class="whitespace-nowrap px-5 py-4 text-ink-muted">{{ dateLabel(order.openedAt) }}</td><td class="whitespace-nowrap px-5 py-4"><span class="rounded-full px-2.5 py-1 text-xs font-semibold" :class="statusTone(order.status)">{{ statusLabel(order.status) }}</span></td><td class="whitespace-nowrap px-5 py-4 text-right font-semibold tabular-nums text-ink">{{ money(order.cost) }}</td></tr></tbody>
        </table>
      </div>
      <p v-else role="status" class="px-5 py-10 text-center text-sm text-ink-muted">No hay órdenes con apertura dentro del período seleccionado.</p>
      <nav v-if="pagination.totalPages > 1" aria-label="Paginación de órdenes" class="flex items-center justify-between border-t border-border px-5 py-4 text-sm"><a v-if="pagination.page > 1" :href="queryUrl(pagination.page - 1)" class="font-semibold text-primary hover:text-primary-hover">Anterior</a><span v-else></span><span class="text-ink-muted">Página {{ pagination.page }} de {{ pagination.totalPages }}</span><a v-if="pagination.page < pagination.totalPages" :href="queryUrl(pagination.page + 1)" class="font-semibold text-primary hover:text-primary-hover">Siguiente</a><span v-else></span></nav>
    </section>
  </div>
</template>
