<script setup>
import { computed } from 'vue'
import { ArrowPathIcon, ExclamationTriangleIcon, TruckIcon } from '@heroicons/vue/24/outline'
import EmptyState from './components/EmptyState.vue'
import PageHeading from './components/PageHeading.vue'
import PanelCard from './components/PanelCard.vue'
import { fieldClass, primaryButton, secondaryButton } from './helpers.js'

const props = defineProps({ data: { type: Object, required: true } })

const summary = computed(() => props.data.summary ?? {})
const filters = computed(() => props.data.filters ?? {})
const options = computed(() => props.data.options ?? { branches: [], domains: [] })
const monthly = computed(() => props.data.monthly ?? [])
const domains = computed(() => props.data.domains ?? [])
const quality = computed(() => props.data.quality ?? [])
const trips = computed(() => props.data.trips ?? [])
const pagination = computed(() => props.data.pagination ?? { page: 1, perPage: 20, total: trips.value.length, pages: 1 })
const dashboardUrl = computed(() => props.data.links?.index ?? '/viajes')
const branchLabel = (id) => options.value.branches.find((branch) => Number(branch.id) === Number(id))?.name ?? `Sucursal #${id}`
const hasTrips = computed(() => Number(summary.value.trips) > 0)
const maxKm = computed(() => Math.max(...monthly.value.map((month) => Number(month.km) || 0), 1))
const maxLiters = computed(() => Math.max(...monthly.value.map((month) => Number(month.liters) || 0), 1))
const consumptionAvailable = computed(() => summary.value.liters_per_100 !== null && summary.value.liters_per_100 !== undefined)

const fmt = (value) => Number(value ?? 0).toLocaleString('es-AR', { maximumFractionDigits: 0 })
const fmt1 = (value) => Number(value ?? 0).toLocaleString('es-AR', { maximumFractionDigits: 1 })
const fmtDate = (value) => {
  const match = String(value ?? '').match(/^(\d{4})-(\d{2})-(\d{2})/)
  return match ? `${match[3]}/${match[2]}/${match[1]}` : 'Sin fecha'
}
const bar = (value, max) => `${Math.max(2, Math.round((Number(value) || 0) / max * 100))}%`
const qualityTone = { success: 'bg-success', warning: 'bg-warning', danger: 'bg-danger' }
const pageUrl = (page) => {
  const query = new URLSearchParams(window.location.search)
  if (page <= 1) query.delete('pagina')
  else query.set('pagina', String(page))
  const suffix = query.toString()
  return `${dashboardUrl.value}${suffix ? `?${suffix}` : ''}`
}
const tripUrl = (id) => `${dashboardUrl.value}/${id}${window.location.search}`
</script>

<template>
  <div>
    <PageHeading eyebrow="Viajes" title="Dashboard de viajes" description="Actividad, kilometraje y combustible del historico importado. Los costos quedan fuera hasta definir la moneda de origen." />

    <PanelCard title="Filtros" class="mb-5">
      <form method="get" class="grid gap-4 md:grid-cols-4 xl:grid-cols-5 xl:items-end">
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
        <label class="block">
          <span class="mb-1.5 block text-sm font-semibold text-ink">Dominio / unidad</span>
          <select name="dominio" :class="fieldClass">
            <option value="">Todos</option>
            <option v-for="domain in options.domains" :key="domain" :value="domain" :selected="domain === filters.domain">{{ domain }}</option>
          </select>
        </label>
        <div class="flex flex-wrap gap-2">
          <button type="submit" :class="primaryButton"><ArrowPathIcon class="mr-2 size-4" aria-hidden="true" />Aplicar</button>
          <a :href="dashboardUrl" :class="secondaryButton">Limpiar</a>
        </div>
      </form>
    </PanelCard>

    <EmptyState v-if="!hasTrips" title="No hay viajes para los filtros indicados" description="Probá ampliar el período o quitar el filtro de sucursal o dominio." />

    <template v-else>
      <section aria-label="Indicadores" class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-5">
        <article class="rounded-xl border border-border bg-surface-raised p-4 shadow-card">
          <p class="text-xs font-semibold text-ink-muted">Viajes</p>
          <p class="mt-2 text-2xl font-bold text-ink">{{ fmt(summary.trips) }}</p>
        </article>
        <article class="rounded-xl border border-border bg-surface-raised p-4 shadow-card">
          <p class="text-xs font-semibold text-ink-muted">Km confiables</p>
          <p class="mt-2 text-2xl font-bold text-ink">{{ fmt(summary.km) }}</p>
          <p class="mt-1 text-xs text-ink-muted">cobertura {{ fmt1(summary.km_coverage) }}%</p>
        </article>
        <article class="rounded-xl border border-border bg-surface-raised p-4 shadow-card">
          <p class="text-xs font-semibold text-ink-muted">Litros cargados</p>
          <p class="mt-2 text-2xl font-bold text-ink">{{ fmt(summary.liters) }}</p>
          <p class="mt-1 text-xs text-ink-muted">{{ fmt(summary.fuel_trips) }} viajes con carga</p>
        </article>
        <article class="rounded-xl border border-border bg-surface-raised p-4 shadow-card">
          <p class="text-xs font-semibold text-ink-muted">Consumo</p>
          <p v-if="consumptionAvailable" class="mt-2 text-2xl font-bold text-ink">{{ fmt1(summary.liters_per_100) }}<span class="ml-1 text-sm font-semibold text-ink-muted">L/100 km</span></p>
          <p v-else class="mt-2 text-lg font-semibold text-ink-muted">Sin datos suficientes</p>
          <p class="mt-1 text-xs text-ink-muted">sobre km con lectura confiable</p>
        </article>
        <article class="rounded-xl border border-border bg-surface-raised p-4 shadow-card">
          <p class="text-xs font-semibold text-ink-muted">Cobertura combustible</p>
          <p class="mt-2 text-2xl font-bold text-ink">{{ fmt1(summary.fuel_coverage) }}%</p>
        </article>
      </section>

      <div class="mb-5 grid gap-5 xl:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)]">
        <PanelCard title="Actividad por mes">
          <p v-if="monthly.length === 0" class="text-sm text-ink-muted">Sin viajes en el período.</p>
          <div v-else class="space-y-3">
            <div class="flex items-center gap-4 text-xs text-ink-muted">
              <span class="inline-flex items-center gap-1"><span class="inline-block size-2.5 rounded-sm bg-primary"></span>Km</span>
              <span class="inline-flex items-center gap-1"><span class="inline-block size-2.5 rounded-sm bg-accent"></span>Litros</span>
            </div>
            <div v-for="month in monthly" :key="month.month" class="grid grid-cols-[5rem_1fr_9rem] items-center gap-3">
              <span class="font-mono text-xs text-ink-muted">{{ month.month }}</span>
              <div class="space-y-1">
                <div class="h-2 rounded-full bg-surface-muted"><div class="h-2 rounded-full bg-primary" :style="{ width: bar(month.km, maxKm) }"></div></div>
                <div class="h-2 rounded-full bg-surface-muted"><div class="h-2 rounded-full bg-accent" :style="{ width: bar(month.liters, maxLiters) }"></div></div>
              </div>
              <span class="text-right text-xs text-ink-muted">{{ fmt(month.trips) }} viajes · {{ fmt(month.km) }} km</span>
            </div>
          </div>
        </PanelCard>

        <PanelCard title="Calidad de datos">
          <ul class="space-y-4">
            <li v-for="item in quality" :key="item.key">
              <div class="flex items-center justify-between gap-3 text-sm">
                <span class="font-semibold text-ink">{{ item.label }}</span>
                <span class="text-ink-muted">{{ fmt(item.value) }} / {{ fmt(item.total) }}</span>
              </div>
              <div class="mt-1.5 h-2 rounded-full bg-surface-muted">
                <div class="h-2 rounded-full" :class="qualityTone[item.tone] ?? 'bg-primary'" :style="{ width: item.total > 0 ? `${Math.round(item.value / item.total * 100)}%` : '0%' }"></div>
              </div>
            </li>
          </ul>
          <p class="mt-4 flex items-start gap-2 rounded-lg bg-surface p-3 text-xs text-ink-muted">
            <ExclamationTriangleIcon class="mt-0.5 size-4 shrink-0 text-warning" aria-hidden="true" />
            El odómetro del sistema anterior no es homogéneo. Se descartan los viajes con regreso anterior a la salida y los saltos mayores a 15.000 km; por eso la cobertura no es del 100%.
          </p>
        </PanelCard>
      </div>

      <PanelCard title="Por unidad (dominio)" :count="domains.length">
        <div class="overflow-x-auto">
          <table class="w-full min-w-[44rem] text-left text-sm">
            <thead class="bg-surface-subtle text-xs uppercase tracking-wide text-ink-muted">
              <tr>
                <th class="px-4 py-3">Dominio</th>
                <th class="px-4 py-3 text-right">Viajes</th>
                <th class="px-4 py-3 text-right">Km confiables</th>
                <th class="px-4 py-3 text-right">Cobertura</th>
                <th class="px-4 py-3 text-right">Litros</th>
                <th class="px-4 py-3 text-right">L/100 km</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-border-subtle">
              <tr v-for="item in domains" :key="item.domain">
                <td class="whitespace-nowrap px-4 py-3 font-mono text-xs font-semibold text-ink">{{ item.domain }}</td>
                <td class="px-4 py-3 text-right text-ink-muted">{{ fmt(item.trips) }}</td>
                <td class="px-4 py-3 text-right font-semibold text-ink">{{ fmt(item.km) }}</td>
                <td class="px-4 py-3 text-right" :class="Number(item.coverage) < 50 ? 'font-semibold text-warning-strong' : 'text-ink-muted'">{{ fmt1(item.coverage) }}%</td>
                <td class="px-4 py-3 text-right text-ink-muted">{{ fmt(item.liters) }}</td>
                <td class="px-4 py-3 text-right font-semibold text-ink">
                  <span v-if="item.liters_per_100 !== null && item.liters_per_100 !== undefined">{{ fmt1(item.liters_per_100) }}</span>
                  <span v-else class="text-ink-muted">Sin datos</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </PanelCard>

      <PanelCard title="Viajes del período" :count="pagination.total" class="mt-5">
        <p class="mb-3 text-sm text-ink-muted">Seleccioná un viaje para consultar su recorrido, combustible, gastos y demás datos importados.</p>
        <div class="overflow-x-auto">
          <table class="w-full min-w-[56rem] text-left text-sm">
            <thead class="bg-surface-subtle text-xs uppercase tracking-wide text-ink-muted"><tr><th class="px-4 py-3">Salida</th><th class="px-4 py-3">Viaje</th><th class="px-4 py-3">Dominio</th><th class="px-4 py-3">Sucursal</th><th class="px-4 py-3 text-right">Odómetro salida</th><th class="px-4 py-3 text-right">Odómetro regreso</th><th class="px-4 py-3 text-right">Litros</th><th class="px-4 py-3 text-right">Detalle</th></tr></thead>
            <tbody class="divide-y divide-border-subtle">
              <tr v-for="trip in trips" :key="trip.id">
                <td class="whitespace-nowrap px-4 py-3 text-ink-muted">{{ fmtDate(trip.fecha_salida) }}</td>
                <td class="px-4 py-3 font-semibold text-ink">#{{ fmt(trip.numero_viaje) }}</td>
                <td class="whitespace-nowrap px-4 py-3 font-mono text-xs text-ink">{{ trip.dominio }}</td>
                <td class="px-4 py-3 text-ink-muted">{{ branchLabel(trip.sucursal_id) }}</td>
                <td class="px-4 py-3 text-right text-ink-muted">{{ trip.km_salida === null ? '—' : fmt(trip.km_salida) }}</td>
                <td class="px-4 py-3 text-right text-ink-muted">{{ trip.km_regreso === null ? '—' : fmt(trip.km_regreso) }}</td>
                <td class="px-4 py-3 text-right text-ink-muted">{{ fmt1(trip.litros) }}</td>
                <td class="px-4 py-3 text-right"><a :href="tripUrl(trip.id)" class="font-semibold text-primary hover:text-primary-hover">Ver detalle</a></td>
              </tr>
            </tbody>
          </table>
        </div>
        <nav v-if="pagination.pages > 1" aria-label="Paginación de viajes" class="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-border-subtle pt-4">
          <p class="text-xs text-ink-muted">Página {{ pagination.page }} de {{ pagination.pages }} · {{ fmt(pagination.total) }} viajes</p>
          <div class="flex gap-2">
            <a :href="pageUrl(pagination.page - 1)" :aria-disabled="pagination.page <= 1" :class="['rounded-lg border border-border px-3 py-2 text-sm font-semibold', pagination.page <= 1 ? 'pointer-events-none opacity-50' : 'text-ink hover:bg-surface-subtle']">Anterior</a>
            <a :href="pageUrl(pagination.page + 1)" :aria-disabled="pagination.page >= pagination.pages" :class="['rounded-lg border border-border px-3 py-2 text-sm font-semibold', pagination.page >= pagination.pages ? 'pointer-events-none opacity-50' : 'text-ink hover:bg-surface-subtle']">Siguiente</a>
          </div>
        </nav>
      </PanelCard>
    </template>
  </div>
</template>
