<script setup>
import { computed } from 'vue'
import { ArrowPathIcon, PlusIcon, TruckIcon } from '@heroicons/vue/24/outline'
import EmptyState from './components/EmptyState.vue'
import PageHeading from './components/PageHeading.vue'
import PanelCard from './components/PanelCard.vue'
import { fieldClass, primaryButton, secondaryButton } from './helpers.js'

const props = defineProps({ data: { type: Object, required: true } })

const summary = computed(() => props.data.summary ?? {})
const filters = computed(() => props.data.filters ?? {})
const options = computed(() => props.data.options ?? { branches: [] })
const statusBreakdown = computed(() => props.data.statusBreakdown ?? [])
const monthly = computed(() => props.data.monthly ?? [])
const branches = computed(() => props.data.branches ?? [])
const currentTrips = computed(() => props.data.currentTrips ?? [])
const links = computed(() => props.data.links ?? {
  index: '/viajes/operativos/dashboard',
  list: '/viajes/operativos',
  create: '/viajes/operativos/nuevo',
  history: '/viajes',
})

const hasData = computed(() => Number(summary.value.total) > 0 || Number(summary.value.active_total) > 0)
const consumptionAvailable = computed(() => summary.value.liters_per_100 !== null && summary.value.liters_per_100 !== undefined)
const directResult = computed(() => Number(summary.value.billing_ars || 0) - Number(summary.value.fuel_cost_ars || 0) - Number(summary.value.expenses_ars || 0))

const maxStatus = computed(() => Math.max(...statusBreakdown.value.map((item) => Number(item.value) || 0), 1))
const maxMonthlyTrips = computed(() => Math.max(...monthly.value.map((item) => Number(item.trips) || 0), 1))
const maxMonthlyKm = computed(() => Math.max(...monthly.value.map((item) => Number(item.km) || 0), 1))
const maxBranchTrips = computed(() => Math.max(...branches.value.map((item) => Number(item.trips) || 0), 1))

const statusTone = {
  success: 'bg-success',
  info: 'bg-info',
  neutral: 'bg-ink-subtle',
  danger: 'bg-danger',
}
const statusBadge = {
  EN_CURSO: 'bg-success-subtle text-success-strong',
  PLANIFICADO: 'bg-info-subtle text-info-strong',
  FINALIZADO: 'bg-surface-muted text-ink-muted',
  CANCELADO: 'bg-danger-subtle text-danger-strong',
}

const kpis = computed(() => [
  { key: 'active', label: 'En curso', value: fmt(summary.value.en_curso), hint: 'Ahora en la ruta' },
  { key: 'planned', label: 'Planificados', value: fmt(summary.value.planificados), hint: 'Próximas salidas' },
  { key: 'finished', label: 'Finalizados', value: fmt(summary.value.finalizados), hint: 'Cerrados en el período' },
  {
    key: 'km',
    label: 'Km recorridos',
    value: fmt(summary.value.km),
    hint: `Cobertura ${fmt1(summary.value.km_coverage)}% de viajes cerrados`,
  },
  {
    key: 'fuel',
    label: 'Combustible',
    value: `${fmt1(summary.value.liters)} L`,
    hint: `${fmtMoney(summary.value.fuel_cost_ars)} cargados`,
  },
  {
    key: 'consumption',
    label: 'Consumo',
    value: consumptionAvailable.value ? `${fmt1(summary.value.liters_per_100)} L/100 km` : 'Sin datos suficientes',
    hint: 'Sobre viajes cerrados con km confiable',
    compact: !consumptionAvailable.value,
  },
])

const fmt = (value) => Number(value ?? 0).toLocaleString('es-AR', { maximumFractionDigits: 0 })
const fmt1 = (value) => Number(value ?? 0).toLocaleString('es-AR', { maximumFractionDigits: 1 })
const fmtMoney = (value) => new Intl.NumberFormat('es-AR', {
  style: 'currency',
  currency: 'ARS',
  maximumFractionDigits: 0,
}).format(Number(value ?? 0))
const fmtDateTime = (value) => {
  const match = String(value ?? '').match(/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})/)
  return match ? `${match[3]}/${match[2]}/${match[1]} ${match[4]}:${match[5]}` : 'Sin fecha'
}
const bar = (value, max) => {
  const amount = Number(value) || 0
  if (amount <= 0 || max <= 0) return '0%'
  return `${Math.max(4, Math.round(amount / max * 100))}%`
}
</script>

<template>
  <div data-testid="trip-operations-dashboard">
    <PageHeading
      eyebrow="Viajes"
      title="Tablero de viajes operativos"
      description="Mirá de un vistazo los viajes en curso, los próximos y los indicadores del período. Los costos y fletes se expresan en pesos; las comisiones del chofer se liquidan en el detalle de cada viaje."
    >
      <template #actions>
        <a :href="links.list" :class="secondaryButton"><TruckIcon class="mr-2 size-4" aria-hidden="true" />Viajes operativos</a>
        <a v-if="data.canOperate" :href="links.create" :class="primaryButton"><PlusIcon class="mr-2 size-4" aria-hidden="true" />Nuevo viaje</a>
      </template>
    </PageHeading>

    <PanelCard title="Filtros" class="mb-5">
      <form method="get" :action="links.index" class="grid gap-4 md:grid-cols-2 xl:grid-cols-4 xl:items-end">
        <label class="block">
          <span class="mb-1.5 block text-sm font-semibold text-ink">Desde</span>
          <input type="date" name="desde" :value="filters.from ?? ''" :class="fieldClass" />
        </label>
        <label class="block">
          <span class="mb-1.5 block text-sm font-semibold text-ink">Hasta</span>
          <input type="date" name="hasta" :value="filters.to ?? ''" :class="fieldClass" />
        </label>
        <label class="block">
          <span class="mb-1.5 block text-sm font-semibold text-ink">Sucursal</span>
          <select name="sucursal" :class="fieldClass">
            <option value="">Todas</option>
            <option v-for="branch in options.branches" :key="branch.id" :value="branch.id" :selected="String(branch.id) === String(filters.branchId ?? '')">{{ branch.code }} · {{ branch.name }}</option>
          </select>
        </label>
        <div class="flex flex-wrap gap-2">
          <button type="submit" :class="primaryButton"><ArrowPathIcon class="mr-2 size-4" aria-hidden="true" />Aplicar</button>
          <a :href="links.index" :class="secondaryButton">Limpiar</a>
        </div>
      </form>
      <p class="mt-3 text-xs text-ink-muted">El período afecta los indicadores y gráficos. Los viajes en curso y planificados se listan siempre.</p>
    </PanelCard>

    <EmptyState
      v-if="!hasData"
      title="Todavía no hay viajes operativos"
      description="Cuando registres el primer viaje vas a ver acá los viajes en curso, los próximos y los indicadores del período."
    />
    <div v-if="!hasData && data.canOperate" class="text-center">
      <a :href="links.create" :class="primaryButton"><PlusIcon class="mr-2 size-4" aria-hidden="true" />Registrar el primer viaje</a>
    </div>

    <template v-else>
      <section aria-label="Indicadores de viajes operativos" class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-3 xl:grid-cols-6">
        <article v-for="kpi in kpis" :key="kpi.key" class="rounded-xl border border-border bg-surface-raised p-4 shadow-card">
          <p class="text-xs font-semibold text-ink-muted">{{ kpi.label }}</p>
          <p class="mt-2 font-bold text-ink" :class="kpi.compact ? 'text-base leading-6' : 'text-2xl'">{{ kpi.value }}</p>
          <p class="mt-1 text-xs text-ink-muted">{{ kpi.hint }}</p>
        </article>
      </section>

      <PanelCard title="Viajes en curso y próximos" :count="currentTrips.length" class="mb-5">
        <p class="mb-3 text-sm text-ink-muted">Estos viajes se muestran siempre, aunque su salida quede fuera del período filtrado.</p>
        <EmptyState
          v-if="currentTrips.length === 0"
          compact
          title="No hay viajes en curso ni planificados"
          description="Cuando registres un viaje operativo va a aparecer acá con su recorrido, equipo y choferes."
        />
        <div v-else class="overflow-x-auto">
          <table class="w-full min-w-[64rem] text-left text-sm">
            <thead class="bg-surface-subtle text-xs uppercase tracking-wide text-ink-muted">
              <tr>
                <th class="px-4 py-3">Viaje</th>
                <th class="px-4 py-3">Estado</th>
                <th class="px-4 py-3">Salida</th>
                <th class="px-4 py-3">Recorrido</th>
                <th class="px-4 py-3">Tractor</th>
                <th class="px-4 py-3">Choferes</th>
                <th class="px-4 py-3 text-right">Detalle</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-border-subtle">
              <tr v-for="trip in currentTrips" :key="trip.id" data-current-trip>
                <td class="whitespace-nowrap px-4 py-3">
                  <span class="font-semibold text-ink">#{{ trip.numero }}</span>
                  <span v-if="trip.sucursal" class="mt-0.5 block text-xs text-ink-muted">{{ trip.sucursal }}</span>
                </td>
                <td class="px-4 py-3">
                  <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold" :class="statusBadge[trip.estado] ?? 'bg-surface-muted text-ink-muted'">{{ trip.estado_label }}</span>
                </td>
                <td class="whitespace-nowrap px-4 py-3 text-ink-muted">{{ fmtDateTime(trip.fecha_salida) }}</td>
                <td class="px-4 py-3">
                  <p class="font-semibold text-ink">{{ trip.origen ?? 'Sin origen' }} → {{ trip.destino ?? 'Sin destino' }}</p>
                  <p class="mt-0.5 text-xs text-ink-muted">{{ trip.paradas }} paradas</p>
                </td>
                <td class="px-4 py-3 text-ink-muted">{{ trip.tractor ?? 'Sin tractor' }}</td>
                <td class="px-4 py-3 text-ink-muted">{{ (trip.choferes ?? []).join(' · ') || 'Sin chofer' }}</td>
                <td class="px-4 py-3 text-right">
                  <a :href="trip.url" class="font-semibold text-primary hover:text-primary-hover">Ver viaje</a>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div class="mt-4 border-t border-border-subtle pt-4">
          <a :href="links.list" :class="secondaryButton">Ver todos los viajes operativos</a>
        </div>
      </PanelCard>

      <div class="mb-5 grid gap-5 xl:grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)]">
        <PanelCard title="Estado de los viajes">
          <ul class="space-y-4">
            <li v-for="item in statusBreakdown" :key="item.key" data-status-row>
              <div class="flex items-center justify-between gap-3 text-sm">
                <span class="font-semibold text-ink">{{ item.label }}</span>
                <span class="tabular-nums text-ink-muted">{{ fmt(item.value) }}</span>
              </div>
              <div
                class="mt-1.5 h-2.5 overflow-hidden rounded-full bg-surface-muted"
                role="meter"
                :aria-label="`${item.label}: ${fmt(item.value)} viajes`"
                aria-valuemin="0"
                :aria-valuemax="maxStatus"
                :aria-valuenow="Number(item.value) || 0"
              >
                <div class="h-full rounded-full" :class="statusTone[item.tone] ?? 'bg-primary'" :style="{ width: bar(item.value, maxStatus) }"></div>
              </div>
            </li>
          </ul>
          <p class="mt-4 text-xs text-ink-muted">En curso y planificados se cuentan siempre. Finalizados y cancelados, según el período.</p>
        </PanelCard>

        <PanelCard title="Últimos 6 meses con actividad">
          <p v-if="monthly.length === 0" class="text-sm text-ink-muted">Sin viajes en el período filtrado.</p>
          <div v-else class="space-y-3">
            <div class="flex items-center gap-4 text-xs text-ink-muted">
              <span class="inline-flex items-center gap-1"><span class="inline-block size-2.5 rounded-sm bg-primary"></span>Viajes</span>
              <span class="inline-flex items-center gap-1"><span class="inline-block size-2.5 rounded-sm bg-accent"></span>Km</span>
            </div>
            <div v-for="month in monthly" :key="month.month" data-month-row class="grid grid-cols-[5rem_1fr_8.5rem] items-center gap-3">
              <span class="font-mono text-xs text-ink-muted">{{ month.month }}</span>
              <div class="space-y-1">
                <div class="h-2 rounded-full bg-surface-muted"><div class="h-2 rounded-full bg-primary" :style="{ width: bar(month.trips, maxMonthlyTrips) }"></div></div>
                <div class="h-2 rounded-full bg-surface-muted"><div class="h-2 rounded-full bg-accent" :style="{ width: bar(month.km, maxMonthlyKm) }"></div></div>
              </div>
              <span class="text-right text-xs text-ink-muted">{{ fmt(month.trips) }} viajes · {{ fmt(month.km) }} km</span>
            </div>
          </div>
        </PanelCard>
      </div>

      <div class="grid gap-5 xl:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)]">
        <PanelCard title="Viajes por sucursal">
          <p v-if="branches.length === 0" class="text-sm text-ink-muted">Sin actividad por sucursal en el período.</p>
          <ul v-else class="space-y-4">
            <li v-for="branch in branches" :key="branch.id" data-branch-row>
              <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1 text-sm">
                <span class="font-semibold text-ink">{{ branch.code }} · {{ branch.name }}</span>
                <span class="text-xs text-ink-muted">{{ fmt(branch.trips) }} del período · {{ fmt(branch.active) }} activos</span>
              </div>
              <div
                class="mt-1.5 h-2.5 overflow-hidden rounded-full bg-surface-muted"
                role="meter"
                :aria-label="`${branch.name}: ${fmt(branch.trips)} viajes del período`"
                aria-valuemin="0"
                :aria-valuemax="maxBranchTrips"
                :aria-valuenow="Number(branch.trips) || 0"
              >
                <div class="h-full rounded-full bg-primary" :style="{ width: bar(branch.trips, maxBranchTrips) }"></div>
              </div>
            </li>
          </ul>
        </PanelCard>

        <PanelCard title="Económico del período">
          <dl class="space-y-3">
            <div class="flex items-baseline justify-between gap-3">
              <dt class="text-sm text-ink-muted">Flete facturado</dt>
              <dd class="text-lg font-bold text-ink">{{ fmtMoney(summary.billing_ars) }}</dd>
            </div>
            <div class="flex items-baseline justify-between gap-3">
              <dt class="text-sm text-ink-muted">Combustible</dt>
              <dd class="text-lg font-semibold text-ink">{{ fmtMoney(summary.fuel_cost_ars) }}</dd>
            </div>
            <div class="flex items-baseline justify-between gap-3">
              <dt class="text-sm text-ink-muted">Gastos operativos</dt>
              <dd class="text-lg font-semibold text-ink">{{ fmtMoney(summary.expenses_ars) }}</dd>
            </div>
            <div class="flex items-baseline justify-between gap-3 border-t border-border-subtle pt-3">
              <dt class="text-sm font-semibold text-ink">Resultado directo</dt>
              <dd class="text-lg font-bold" :class="directResult < 0 ? 'text-danger-strong' : 'text-ink'">{{ fmtMoney(directResult) }}</dd>
            </div>
          </dl>
          <p class="mt-4 text-xs text-ink-muted">El resultado directo es flete facturado menos combustible y gastos. No incluye comisiones ni otros haberes del chofer; se liquidan en el detalle del viaje.</p>
        </PanelCard>
      </div>
    </template>
  </div>
</template>
