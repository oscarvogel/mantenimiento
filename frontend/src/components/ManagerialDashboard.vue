<script setup>
import { computed } from 'vue'
import { ArrowRightIcon, ChevronRightIcon } from '@heroicons/vue/20/solid'
import {
  ChartBarIcon,
  CheckCircleIcon,
  ClockIcon,
  ExclamationTriangleIcon,
  TruckIcon,
  WrenchScrewdriverIcon,
} from '@heroicons/vue/24/outline'
import CountUp from './CountUp.vue'
import MetricCard from './MetricCard.vue'
import MaintenanceStatus from './MaintenanceStatus.vue'

const props = defineProps({
  dashboard: { type: Object, required: true },
  firstName: { type: String, required: true },
})

const metrics = computed(() => props.dashboard.metrics || {})
const financial = computed(() => props.dashboard.financial || {})
const formatCurrency = (value) => new Intl.NumberFormat('es-AR', {
  style: 'currency',
  currency: 'ARS',
  maximumFractionDigits: 0,
}).format(Number(value) || 0)
const toNullableNumber = (value) => (
  value === null || value === undefined || value === '' || !Number.isFinite(Number(value))
    ? null
    : Number(value)
)
const formatVariation = (value) => {
  const number = Number(value) || 0
  const sign = number > 0 ? '+' : ''
  return `${sign}${number.toFixed(1).replace('.', ',')}% vs. mes anterior`
}
const financialHistoryMax = computed(() => Math.max(
  1,
  ...(financial.value.history || []).map((item) => Number(item.totalArs) || 0),
))
const financialVariationValue = computed(() => toNullableNumber(financial.value.variationPercentage))
const preventiveComplianceValue = computed(() => toNullableNumber(metrics.value.preventiveCompliance))
const preventiveTotal = computed(() => (
  (metrics.value.maintenanceScheduled ?? 0)
  + (metrics.value.maintenanceDueSoon ?? 0)
  + (metrics.value.maintenanceOverdue ?? 0)
  + (metrics.value.maintenanceMissingData ?? 0)
))

const preventiveSegments = computed(() => {
  const total = Math.max(preventiveTotal.value, 1)
  return [
    { label: 'Al día', value: metrics.value.maintenanceScheduled ?? 0, width: ((metrics.value.maintenanceScheduled ?? 0) / total) * 100, className: 'bg-success' },
    { label: 'Próximos', value: metrics.value.maintenanceDueSoon ?? 0, width: ((metrics.value.maintenanceDueSoon ?? 0) / total) * 100, className: 'bg-warning' },
    { label: 'Vencidos', value: metrics.value.maintenanceOverdue ?? 0, width: ((metrics.value.maintenanceOverdue ?? 0) / total) * 100, className: 'bg-danger' },
    { label: 'Sin datos', value: metrics.value.maintenanceMissingData ?? 0, width: ((metrics.value.maintenanceMissingData ?? 0) / total) * 100, className: 'bg-ink-subtle' },
  ]
})

const readingAlerts = computed(() => props.dashboard.readingAttention || [])
const readingPendingCount = computed(() => (
  (metrics.value.equipmentWithoutReading ?? 0) + (metrics.value.equipmentWithStaleReading ?? 0)
))
const criticalMaintenance = computed(() => (props.dashboard.upcomingMaintenance || [])
  .filter((item) => ['VENCIDO', 'PROXIMO', 'SIN_DATOS'].includes(item.status))
  .slice(0, 6))

const hasFinancialActivity = computed(() => {
  const amounts = [
    financial.value.currentMonthArs,
    financial.value.previousMonthArs,
    financial.value.preventiveArs,
    financial.value.correctiveArs,
    financial.value.averagePerEquipmentArs,
    financial.value.equipmentWithCost,
  ]
  const historyHasValues = (financial.value.history || []).some((item) => Number(item.totalArs) > 0)
  const topEquipmentHasValues = (financial.value.topEquipment || []).length > 0

  return amounts.some((value) => Number(value) > 0) || historyHasValues || topEquipmentHasValues
})

const fleetReadingCoverage = computed(() => {
  const total = Math.max(metrics.value.equipmentActive ?? metrics.value.equipmentTotal ?? 0, 1)
  const problems = (metrics.value.equipmentWithoutReading ?? 0) + (metrics.value.equipmentWithStaleReading ?? 0)
  return Math.max(0, Math.round(((total - problems) / total) * 100))
})

const readingQualityBars = computed(() => {
  const active = Math.max(Number(metrics.value.equipmentActive ?? metrics.value.equipmentTotal ?? 0), 0)
  const stale = Math.max(Number(metrics.value.equipmentWithStaleReading ?? 0), 0)
  const missing = Math.max(Number(metrics.value.equipmentWithoutReading ?? 0), 0)
  const updated = Math.max(active - stale - missing, 0)
  const toPercentage = (value) => (active > 0 ? Math.round((value / active) * 100) : 0)
  const bars = [
    { key: 'updated', label: 'Actualizadas', value: toPercentage(updated), className: 'bg-success' },
    { key: 'stale', label: 'Antiguas', value: toPercentage(stale), className: 'bg-warning' },
    { key: 'missing', label: 'Sin lectura', value: toPercentage(missing), className: 'bg-danger' },
  ]
  const maxHeight = 112

  return bars.map((bar) => ({
    ...bar,
    height: bar.value === 0 ? 0 : Math.max(4, Math.round((bar.value / 100) * maxHeight)),
  }))
})

const executiveAlerts = computed(() => [
  {
    label: 'Preventivos vencidos',
    value: metrics.value.maintenanceOverdue ?? 0,
    detail: 'Servicios que ya superaron su límite',
    href: props.dashboard.links.maintenanceOverdue,
    urgent: (metrics.value.maintenanceOverdue ?? 0) > 0,
  },
  {
    label: 'Equipos sin lectura',
    value: metrics.value.equipmentWithoutReading ?? 0,
    detail: 'No tienen km/horas registrados',
    href: props.dashboard.links.equipment,
    urgent: (metrics.value.equipmentWithoutReading ?? 0) > 0,
  },
  {
    label: 'Lecturas antiguas',
    value: metrics.value.equipmentWithStaleReading ?? 0,
    detail: `Más de ${metrics.value.staleReadingDays ?? 7} días sin actualización`,
    href: props.dashboard.links.equipment,
    urgent: (metrics.value.equipmentWithStaleReading ?? 0) > 0,
  },
  {
    label: 'OT correctivas abiertas',
    value: metrics.value.openCorrectiveOrders ?? 0,
    detail: 'Trabajos correctivos todavía pendientes',
    href: props.dashboard.links.orders,
    urgent: (metrics.value.openCorrectiveOrders ?? 0) > 0,
  },
])
const actionableExecutiveAlerts = computed(() => executiveAlerts.value.filter((alert) => alert.urgent))
</script>

<template>
  <div>
    <header v-reveal class="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
      <div>
        <div class="inline-flex items-center gap-2 rounded-full bg-primary-subtle px-3 py-1 text-xs font-bold uppercase tracking-wide text-primary">
          <ChartBarIcon class="size-4" aria-hidden="true" />
          Dashboard gerencial
        </div>
        <h1 class="mt-3 text-balance text-3xl font-bold tracking-tight text-ink sm:text-4xl">
          Buen día, {{ firstName }}
        </h1>
        <p class="mt-2 max-w-3xl text-sm leading-6 text-ink-muted sm:text-base">
          Estado general de la flota, cumplimiento del mantenimiento y puntos que requieren una decisión.
        </p>
      </div>
      <div class="flex flex-col gap-2 sm:flex-row">
        <a
          v-if="dashboard.links.equipment !== '#'"
          :href="dashboard.links.equipment"
          class="ui-interactive inline-flex min-h-11 items-center justify-center gap-2 rounded-lg border border-primary bg-surface px-4 py-2.5 text-sm font-semibold text-primary transition hover:bg-primary-subtle"
        >
          <TruckIcon class="size-5" aria-hidden="true" />
          Ver flota
        </a>
        <a
          v-if="dashboard.links.maintenance !== '#'"
          :href="dashboard.links.maintenance"
          class="ui-interactive ui-glare inline-flex min-h-11 items-center justify-center gap-2 rounded-lg bg-primary px-4 py-2.5 text-sm font-semibold text-primary-foreground shadow-sm transition hover:bg-primary-hover"
        >
          <WrenchScrewdriverIcon class="size-5" aria-hidden="true" />
          Ver mantenimiento
        </a>
      </div>
    </header>

    <section v-reveal="{ delay: 40 }" aria-label="Indicadores gerenciales" class="mt-7 grid gap-4 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-5">
      <MetricCard label="Equipos activos" :value="metrics.equipmentActive ?? metrics.equipmentTotal" tone="primary" :href="dashboard.links.equipment" link-label="Ver flota" />
      <MetricCard
        label="Cumplimiento"
        :value="preventiveComplianceValue === null ? 'Sin datos' : preventiveComplianceValue"
        :suffix="preventiveComplianceValue === null ? '' : '%'"
        tone="primary"
        :href="dashboard.links.maintenance"
        link-label="Ver preventivos"
      />
      <MetricCard label="Preventivos vencidos" :value="metrics.maintenanceOverdue ?? 0" tone="overdue" :href="dashboard.links.maintenanceOverdue" link-label="Atender" />
      <MetricCard label="OT abiertas" :value="metrics.openOrders ?? 0" tone="orders" :href="dashboard.links.orders" link-label="Ver órdenes" />
      <MetricCard label="Lecturas pendientes" :value="readingPendingCount" tone="due" :href="dashboard.links.equipment" link-label="Revisar lecturas" />
    </section>

    <section v-reveal="{ delay: 80 }" class="mt-6 rounded-xl border border-border bg-surface-raised p-5 sm:p-6" aria-labelledby="financial-title">
      <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
          <h2 id="financial-title" class="text-base font-bold text-ink sm:text-lg">Resumen financiero del mes</h2>
          <p class="mt-1 text-sm text-ink-muted">
            Costos reales de órdenes finalizadas{{ financial.periodLabel ? ` · ${financial.periodLabel}` : '' }}.
          </p>
        </div>
        <a
          v-if="dashboard.links.financialDetail !== '#'"
          :href="dashboard.links.financialDetail"
          class="inline-flex items-center gap-1 text-sm font-semibold text-primary hover:text-primary-hover"
        >
          Ver detalle <ArrowRightIcon class="size-4" aria-hidden="true" />
        </a>
      </div>

      <div
        v-if="financial.missingExchangeRateCount"
        class="mt-4 rounded-lg border border-warning/30 bg-warning-subtle px-4 py-3 text-sm text-warning-strong"
      >
        <CountUp :value="Number(financial.missingExchangeRateCount) || 0" /> gasto(s) en moneda extranjera no se incluyeron porque no tienen importe histórico congelado en ARS.
      </div>

      <template v-if="hasFinancialActivity">
        <div class="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
        <div class="rounded-lg bg-surface-subtle p-4">
          <p class="text-xs font-semibold uppercase tracking-wide text-ink-muted">Gastos del mes</p>
          <p class="mt-2 text-2xl font-bold tracking-tight text-ink"><CountUp :value="Number(financial.currentMonthArs) || 0" :formatter="formatCurrency" /></p>
          <p class="mt-1 text-xs" :class="Number(financial.variationPercentage) > 0 ? 'text-danger' : 'text-ink-muted'">
            <CountUp v-if="financialVariationValue !== null" :value="financialVariationValue" :formatter="formatVariation" />
            <template v-else>Sin base comparable</template>
          </p>
        </div>
        <div class="rounded-lg bg-surface-subtle p-4">
          <p class="text-xs font-semibold uppercase tracking-wide text-ink-muted">Preventivo</p>
          <p class="mt-2 text-xl font-bold text-ink"><CountUp :value="Number(financial.preventiveArs) || 0" :formatter="formatCurrency" /></p>
        </div>
        <div class="rounded-lg bg-surface-subtle p-4">
          <p class="text-xs font-semibold uppercase tracking-wide text-ink-muted">Correctivo</p>
          <p class="mt-2 text-xl font-bold text-ink"><CountUp :value="Number(financial.correctiveArs) || 0" :formatter="formatCurrency" /></p>
        </div>
        <div class="rounded-lg bg-surface-subtle p-4">
          <p class="text-xs font-semibold uppercase tracking-wide text-ink-muted">Promedio / equipo</p>
          <p class="mt-2 text-xl font-bold text-ink"><CountUp :value="Number(financial.averagePerEquipmentArs) || 0" :formatter="formatCurrency" /></p>
          <p class="mt-1 text-xs text-ink-muted"><CountUp :value="Number(financial.equipmentWithCost) || 0" /> con gasto</p>
        </div>
        <div class="rounded-lg bg-surface-subtle p-4">
          <p class="text-xs font-semibold uppercase tracking-wide text-ink-muted">Mes anterior</p>
          <p class="mt-2 text-xl font-bold text-ink"><CountUp :value="Number(financial.previousMonthArs) || 0" :formatter="formatCurrency" /></p>
        </div>
        </div>

        <div class="mt-6 grid gap-6 lg:grid-cols-[minmax(0,1.4fr)_minmax(18rem,0.8fr)]">
        <div>
          <h3 class="text-sm font-bold text-ink">Evolución últimos 6 meses</h3>
          <div class="mt-4 flex h-44 items-end gap-3 rounded-lg bg-surface-subtle px-4 py-4">
            <div v-for="item in financial.history" :key="item.month" class="flex min-w-0 flex-1 flex-col items-center justify-end gap-2">
              <span class="text-[11px] font-semibold text-ink-muted"><CountUp :value="Number(item.totalArs) || 0" :formatter="formatCurrency" /></span>
              <div class="flex h-28 w-full items-end justify-center">
                <div
                  class="w-full max-w-16 rounded-t-md bg-primary"
                  :style="{ height: `${item.totalArs > 0 ? Math.max(4, Math.round((item.totalArs / financialHistoryMax) * 112)) : 0}px` }"
                />
              </div>
              <span class="text-xs text-ink-muted">{{ item.label }}</span>
            </div>
          </div>
        </div>

        <div>
          <h3 class="text-sm font-bold text-ink">Top 5 equipos por costo</h3>
          <div v-if="financial.topEquipment?.length" class="mt-3 divide-y divide-border-subtle rounded-lg border border-border-subtle">
            <a
              v-for="item in financial.topEquipment"
              :key="item.equipmentId"
              :href="dashboard.links.equipment !== '#' ? `${dashboard.links.equipment}/${item.equipmentId}` : '#'"
              class="flex items-center justify-between gap-3 px-3.5 py-3 hover:bg-primary-subtle/40"
            >
              <span class="truncate text-sm font-semibold text-ink">{{ item.equipmentCode }}</span>
              <strong class="shrink-0 text-sm text-ink"><CountUp :value="Number(item.totalArs) || 0" :formatter="formatCurrency" /></strong>
            </a>
          </div>
          <p v-else class="mt-3 rounded-lg bg-surface-subtle px-4 py-5 text-sm text-ink-muted">Todavía no hay costos finalizados en el mes.</p>
        </div>
        </div>
      </template>
      <div v-else class="mt-5 flex items-start gap-3 rounded-lg bg-surface-subtle px-4 py-4" data-testid="financial-empty">
        <ChartBarIcon class="mt-0.5 size-5 shrink-0 text-ink-subtle" aria-hidden="true" />
        <div>
          <p class="font-semibold text-ink">Todavía no hay costos registrados</p>
          <p class="mt-1 text-sm text-ink-muted">Cuando se cierren órdenes con costos, vas a ver acá la evolución mensual y los equipos con mayor gasto.</p>
        </div>
      </div>
    </section>

    <div class="mt-6 grid items-start gap-6 xl:grid-cols-[minmax(0,1.55fr)_minmax(20rem,0.85fr)]">
      <main class="min-w-0 space-y-6">
        <section v-reveal="{ delay: 100 }" class="rounded-xl border border-border bg-surface-raised p-5 sm:p-6" aria-labelledby="health-title">
          <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
            <div>
              <h2 id="health-title" class="text-base font-bold text-ink sm:text-lg">Salud del mantenimiento preventivo</h2>
              <p class="mt-1 text-sm text-ink-muted">Distribución actual de los servicios planificados.</p>
            </div>
            <div class="text-left sm:text-right">
              <p class="text-3xl font-bold tracking-tight text-ink">
                <CountUp v-if="preventiveComplianceValue !== null" :value="preventiveComplianceValue" suffix="%" />
                <template v-else>Sin datos</template>
              </p>
              <p class="text-xs font-medium text-ink-muted">de cumplimiento</p>
            </div>
          </div>

          <div class="mt-6 flex h-4 overflow-hidden rounded-full bg-surface-muted" aria-hidden="true">
            <div
              v-for="segment in preventiveSegments"
              :key="segment.label"
              :class="segment.className"
              :style="{ width: `${segment.width}%` }"
              class="h-full transition-all"
            />
          </div>
          <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div v-for="segment in preventiveSegments" :key="`${segment.label}-legend`" class="rounded-lg bg-surface-subtle px-3 py-3">
              <p class="text-xs font-medium text-ink-muted">{{ segment.label }}</p>
              <p class="mt-1 text-xl font-bold text-ink"><CountUp :value="Number(segment.value) || 0" /></p>
            </div>
          </div>
        </section>

        <section v-reveal="{ delay: 140 }" class="overflow-hidden rounded-xl border border-border bg-surface-raised" aria-labelledby="readings-title">
          <div class="flex items-center justify-between gap-4 border-b border-border-subtle px-5 py-4 sm:px-6">
            <div>
              <h2 id="readings-title" class="text-base font-bold text-ink sm:text-lg">Control de lecturas</h2>
              <p class="mt-0.5 text-sm text-ink-muted">Equipos sin km/horas o con información demasiado antigua.</p>
            </div>
            <div class="hidden text-right sm:block">
              <p class="text-2xl font-bold text-ink"><CountUp :value="fleetReadingCoverage" suffix="%" /></p>
              <p class="text-xs text-ink-muted">flota actualizada</p>
            </div>
          </div>

          <div v-if="readingAlerts.length" class="divide-y divide-border-subtle">
            <a
              v-for="(item, index) in readingAlerts"
              :key="item.equipmentId"
              :href="item.detailUrl || dashboard.links.equipment"
              :style="{ '--ui-stagger-index': index }"
              class="ui-stagger-item group flex items-center gap-4 px-5 py-4 transition hover:bg-surface-muted sm:px-6"
            >
              <span
                class="flex size-11 shrink-0 items-center justify-center rounded-lg"
                :class="item.status === 'SIN_LECTURA' ? 'bg-danger-subtle text-danger' : 'bg-warning-subtle text-warning-strong'"
              >
                <ExclamationTriangleIcon class="size-6" aria-hidden="true" />
              </span>
              <span class="min-w-0 flex-1">
                <span class="block truncate text-sm font-semibold text-ink">{{ item.equipment }}</span>
                <span class="mt-1 block text-sm text-ink-muted">{{ item.detail }}<template v-if="item.branchName"> · {{ item.branchName }}</template></span>
              </span>
              <span class="hidden rounded-full px-2.5 py-1 text-xs font-semibold sm:inline-flex" :class="item.status === 'SIN_LECTURA' ? 'bg-danger-subtle text-danger' : 'bg-warning-subtle text-warning-strong'">
                {{ item.statusLabel }}
              </span>
              <ChevronRightIcon class="size-5 shrink-0 text-ink-subtle transition group-hover:translate-x-0.5 group-hover:text-primary" aria-hidden="true" />
            </a>
          </div>
          <div v-else class="flex items-start gap-3 px-5 py-6 sm:px-6">
            <CheckCircleIcon class="mt-0.5 size-6 shrink-0 text-success" aria-hidden="true" />
            <div>
              <p class="font-semibold text-ink">Lecturas actualizadas</p>
              <p class="mt-1 text-sm text-ink-muted">No se detectaron equipos activos sin lectura ni con lecturas antiguas.</p>
            </div>
          </div>
        </section>

        <section v-reveal="{ delay: 180 }" class="overflow-hidden rounded-xl border border-border bg-surface-raised" aria-labelledby="maintenance-attention-title">
          <div class="flex items-center justify-between gap-4 border-b border-border-subtle px-5 py-4 sm:px-6">
            <div>
              <h2 id="maintenance-attention-title" class="text-base font-bold text-ink sm:text-lg">Mantenimientos que requieren seguimiento</h2>
              <p class="mt-0.5 text-sm text-ink-muted">Vencidos, próximos y servicios sin datos suficientes.</p>
            </div>
            <a v-if="dashboard.links.maintenance !== '#'" :href="dashboard.links.maintenance" class="hidden items-center gap-1 text-sm font-semibold text-primary hover:text-primary-hover sm:inline-flex">
              Ver todos <ArrowRightIcon class="size-4" aria-hidden="true" />
            </a>
          </div>
          <div v-if="criticalMaintenance.length" class="divide-y divide-border-subtle">
            <a
              v-for="(item, index) in criticalMaintenance"
              :key="item.id || item.planId"
              :href="item.actionUrl || item.detailUrl || dashboard.links.maintenance"
              :style="{ '--ui-stagger-index': index }"
              class="ui-stagger-item group flex items-center gap-4 px-5 py-4 transition hover:bg-surface-muted sm:px-6"
            >
              <span class="flex size-10 shrink-0 items-center justify-center rounded-lg" :class="item.status === 'VENCIDO' ? 'bg-danger-subtle text-danger' : item.status === 'PROXIMO' ? 'bg-warning-subtle text-warning-strong' : 'bg-surface-muted text-ink-muted'">
                <ExclamationTriangleIcon v-if="item.status === 'VENCIDO'" class="size-5" aria-hidden="true" />
                <ClockIcon v-else class="size-5" aria-hidden="true" />
              </span>
              <span class="min-w-0 flex-1">
                <span class="block truncate text-sm font-semibold text-ink">{{ item.equipment }} · {{ item.service }}</span>
                <span class="mt-1 block text-sm" :class="item.status === 'VENCIDO' ? 'font-semibold text-danger' : 'text-ink-muted'">{{ item.remaining }}</span>
              </span>
              <MaintenanceStatus :status="item.tone" :label="item.statusLabel" />
              <ChevronRightIcon class="size-5 shrink-0 text-ink-subtle group-hover:text-primary" aria-hidden="true" />
            </a>
          </div>
          <div v-else class="flex items-start gap-3 px-5 py-6 sm:px-6">
            <CheckCircleIcon class="mt-0.5 size-6 shrink-0 text-success" aria-hidden="true" />
            <p class="font-semibold text-ink">No hay mantenimientos que requieran seguimiento inmediato.</p>
          </div>
        </section>
      </main>

      <aside class="space-y-6">
        <section v-reveal="{ delay: 120 }" class="rounded-xl border border-border bg-surface-raised p-5 sm:p-6" aria-labelledby="executive-title">
          <h2 id="executive-title" class="text-base font-bold text-ink sm:text-lg">Atención ejecutiva</h2>
          <p class="mt-1 text-sm text-ink-muted">Lo que conviene revisar primero.</p>
          <div v-if="actionableExecutiveAlerts.length" class="mt-4 space-y-2.5">
            <a
              v-for="(alert, index) in actionableExecutiveAlerts"
              :key="alert.label"
              :href="alert.href"
              :style="{ '--ui-stagger-index': index }"
              class="ui-card-interactive ui-stagger-item group flex items-center gap-3 rounded-lg border border-border-subtle bg-surface px-3.5 py-3 transition hover:border-primary/30 hover:bg-primary-subtle/40"
            >
              <span class="flex size-10 shrink-0 items-center justify-center rounded-lg" :class="alert.urgent ? 'bg-danger-subtle text-danger' : 'bg-success-subtle text-success'">
                <ExclamationTriangleIcon v-if="alert.urgent" class="size-5" aria-hidden="true" />
                <CheckCircleIcon v-else class="size-5" aria-hidden="true" />
              </span>
              <span class="min-w-0 flex-1">
                <span class="flex items-center justify-between gap-2">
                  <span class="text-sm font-semibold text-ink group-hover:text-primary">{{ alert.label }}</span>
                  <strong class="text-base text-ink"><CountUp :value="Number(alert.value) || 0" /></strong>
                </span>
                <span class="mt-0.5 block text-xs leading-5 text-ink-muted">{{ alert.detail }}</span>
              </span>
            </a>
          </div>
          <div v-else class="mt-4 flex items-start gap-3 rounded-lg bg-surface-subtle px-3.5 py-3">
            <CheckCircleIcon class="mt-0.5 size-5 shrink-0 text-success" aria-hidden="true" />
            <p class="text-sm font-semibold text-ink">No hay alertas ejecutivas pendientes.</p>
          </div>
        </section>

        <section v-reveal="{ delay: 160 }" class="rounded-xl border border-border bg-surface-raised p-5 sm:p-6" aria-labelledby="reading-chart-title">
          <h2 id="reading-chart-title" class="text-base font-bold text-ink sm:text-lg">Calidad de información</h2>
          <p class="mt-1 text-sm text-ink-muted">Distribución porcentual del estado de las lecturas de la flota activa.</p>
          <div class="mt-5 grid h-52 grid-cols-3 items-end gap-3 overflow-hidden rounded-lg bg-surface-subtle px-4 py-5">
            <div
              v-for="bar in readingQualityBars"
              :key="bar.key"
              class="flex min-w-0 flex-col items-center justify-end gap-2"
            >
              <span class="text-xs font-bold text-ink"><CountUp :value="bar.value" suffix="%" /></span>
              <div class="flex h-32 w-full items-end justify-center overflow-hidden">
                <div
                  :data-testid="`reading-quality-${bar.key}`"
                  class="w-full max-w-16 rounded-t-md transition-[height]"
                  :class="bar.className"
                  :style="{ height: `${bar.height}px` }"
                />
              </div>
              <span class="text-center text-xs text-ink-muted">{{ bar.label }}</span>
            </div>
          </div>
          <p class="mt-3 text-xs leading-5 text-ink-muted">Se considera antigua una lectura con más de <CountUp :value="Number(metrics.staleReadingDays ?? 7)" /> días. Cobertura actual: <CountUp :value="fleetReadingCoverage" suffix="%" />.</p>
        </section>
      </aside>
    </div>
  </div>
</template>
