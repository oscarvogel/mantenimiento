<script setup>
import { computed } from 'vue'
import { ArrowPathIcon, ChartBarSquareIcon, ExclamationTriangleIcon } from '@heroicons/vue/24/outline'
import EmptyState from './components/EmptyState.vue'
import PageHeading from './components/PageHeading.vue'
import PanelCard from './components/PanelCard.vue'
import { fieldClass, primaryButton, secondaryButton } from './helpers.js'

const props = defineProps({ data: { type: Object, required: true } })

const filters = computed(() => props.data.filters ?? {})
const options = computed(() => props.data.options ?? { branches: [], domains: [], qualities: [] })
const summary = computed(() => props.data.summary ?? {})
const trips = computed(() => props.data.trips ?? [])
const pagination = computed(() => props.data.pagination ?? { page: 1, pages: 1, total: 0 })
const links = computed(() => props.data.links ?? { index: '/viajes/rentabilidad', history: '/viajes' })

const fmt = (value) => Number(value ?? 0).toLocaleString('es-AR', { maximumFractionDigits: 0 })
const fmt2 = (value) => Number(value ?? 0).toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const fmtPct = (value) => value === null || value === undefined ? 'Sin datos' : `${Number(value).toLocaleString('es-AR', { maximumFractionDigits: 1 })}%`
const fmtDate = (value) => {
  const match = String(value ?? '').match(/^(\d{4})-(\d{2})-(\d{2})/)
  return match ? `${match[3]}/${match[2]}/${match[1]}` : 'Sin fecha'
}
const qualityTone = {
  COMPLETO: 'bg-success-subtle text-success-strong',
  PARCIAL: 'bg-warning-subtle text-warning-strong',
  SIN_FACTURA: 'bg-warning-subtle text-warning-strong',
  SIN_COSTOS: 'bg-danger-subtle text-danger-strong',
  SIN_FLETE: 'bg-surface-muted text-ink-muted',
}
const marginTone = (value) => value === null || value === undefined ? 'text-ink-muted' : (Number(value) < 0 ? 'text-danger-strong' : 'text-success-strong')
const pageUrl = (page) => {
  const query = new URLSearchParams(window.location.search)
  if (page <= 1) query.delete('pagina')
  else query.set('pagina', String(page))
  const suffix = query.toString()
  return `${links.value.index}${suffix ? `?${suffix}` : ''}`
}
const tripUrl = (id) => `${links.value.history}/${id}`
</script>

<template>
  <div>
    <PageHeading eyebrow="Viajes" title="Rentabilidad de viajes" description="Ingreso facturado menos costos directos del viaje. Solo se calcula margen cuando hay factura vinculada y costos; el resto se muestra como sin datos suficientes.">
      <template #actions>
        <a :href="links.history" :class="secondaryButton"><ChartBarSquareIcon class="mr-2 size-4" aria-hidden="true" />Historial</a>
      </template>
    </PageHeading>

    <PanelCard title="Filtros" class="mb-5">
      <form method="get" :action="links.index" class="grid gap-4 md:grid-cols-2 xl:grid-cols-6 xl:items-end">
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
        <label class="block">
          <span class="mb-1.5 block text-sm font-semibold text-ink">Calidad del dato</span>
          <select name="calidad" :class="fieldClass">
            <option value="">Todas</option>
            <option v-for="quality in options.qualities" :key="quality.value" :value="quality.value" :selected="quality.value === filters.quality">{{ quality.label }}</option>
          </select>
        </label>
        <label class="block">
          <span class="mb-1.5 block text-sm font-semibold text-ink">Orden</span>
          <select name="orden" :class="fieldClass">
            <option value="margen_desc" :selected="filters.sort === 'margen_desc'">Mejor margen primero</option>
            <option value="margen_asc" :selected="filters.sort === 'margen_asc'">Peor margen primero</option>
            <option value="fecha_desc" :selected="filters.sort === 'fecha_desc'">Más recientes primero</option>
          </select>
        </label>
        <div class="flex flex-wrap gap-2 md:col-span-2 xl:col-span-6">
          <button type="submit" :class="primaryButton"><ArrowPathIcon class="mr-2 size-4" aria-hidden="true" />Aplicar</button>
          <a :href="links.index" :class="secondaryButton">Limpiar</a>
        </div>
      </form>
    </PanelCard>

    <EmptyState v-if="trips.length === 0" title="No hay viajes para los filtros indicados" description="Probá ampliar el período o quitar filtros. Si el paquete histórico no trae facturación, primero hay que importarla y revisar los vínculos." />

    <template v-else>
      <section aria-label="Indicadores" class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-5">
        <article class="rounded-xl border border-border bg-surface-raised p-4 shadow-card">
          <p class="text-xs font-semibold text-ink-muted">Viajes analizados</p>
          <p class="mt-2 text-2xl font-bold text-ink">{{ fmt(summary.trips_analyzable) }} <span class="text-sm font-semibold text-ink-muted">/ {{ fmt(summary.trips_total) }}</span></p>
          <p class="mt-1 text-xs text-ink-muted">cobertura factura {{ fmtPct(summary.invoice_coverage) }}</p>
        </article>
        <article class="rounded-xl border border-border bg-surface-raised p-4 shadow-card">
          <p class="text-xs font-semibold text-ink-muted">Ingresos facturados</p>
          <p class="mt-2 text-2xl font-bold text-ink">$ {{ fmt(summary.revenue) }}</p>
        </article>
        <article class="rounded-xl border border-border bg-surface-raised p-4 shadow-card">
          <p class="text-xs font-semibold text-ink-muted">Costos directos</p>
          <p class="mt-2 text-2xl font-bold text-ink">$ {{ fmt(summary.direct_cost) }}</p>
          <p class="mt-1 text-xs text-ink-muted">combustible + gastos + viáticos + chofer</p>
        </article>
        <article class="rounded-xl border border-border bg-surface-raised p-4 shadow-card">
          <p class="text-xs font-semibold text-ink-muted">Margen</p>
          <p class="mt-2 text-2xl font-bold" :class="marginTone(summary.margin)">$ {{ fmt(summary.margin) }}</p>
        </article>
        <article class="rounded-xl border border-border bg-surface-raised p-4 shadow-card">
          <p class="text-xs font-semibold text-ink-muted">Margen %</p>
          <p class="mt-2 text-2xl font-bold" :class="marginTone(summary.margin)">{{ fmtPct(summary.margin_pct) }}</p>
        </article>
      </section>

      <PanelCard title="Viajes" :count="pagination.total">
        <div class="mb-4 flex items-start gap-2 rounded-lg bg-surface p-3 text-xs text-ink-muted">
          <ExclamationTriangleIcon class="mt-0.5 size-4 shrink-0 text-warning" aria-hidden="true" />
          <p>Ingreso = facturas vinculadas confirmadas o automáticas, netas de notas de crédito. Costos = combustible, gastos de viaje, viáticos/otros haberes y porcentaje del chofer sobre el flete de tramo legacy. No incluye mantenimiento, neumáticos, seguros ni depreciación. Si falta factura o costo, el margen queda como sin datos suficientes.</p>
        </div>
        <div class="overflow-x-auto">
          <table class="w-full min-w-[72rem] text-left text-sm">
            <thead class="bg-surface-subtle text-xs uppercase tracking-wide text-ink-muted">
              <tr>
                <th class="px-3 py-3">Viaje</th>
                <th class="px-3 py-3">Fecha</th>
                <th class="px-3 py-3 text-right">Ingreso facturado</th>
                <th class="px-3 py-3 text-right">Combustible</th>
                <th class="px-3 py-3 text-right">Gastos</th>
                <th class="px-3 py-3 text-right">Viáticos</th>
                <th class="px-3 py-3 text-right">Chofer</th>
                <th class="px-3 py-3 text-right">Costo directo</th>
                <th class="px-3 py-3 text-right">Margen</th>
                <th class="px-3 py-3 text-right">Margen %</th>
                <th class="px-3 py-3">Calidad</th>
                <th class="px-3 py-3 text-right">Detalle</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-border-subtle">
              <tr v-for="trip in trips" :key="trip.id">
                <td class="whitespace-nowrap px-3 py-3"><span class="font-semibold text-ink">#{{ trip.numero_viaje }}</span><span class="ml-2 font-mono text-xs text-ink-muted">{{ trip.dominio }}</span></td>
                <td class="whitespace-nowrap px-3 py-3 text-ink-muted">{{ fmtDate(trip.fecha_salida) }}</td>
                <td class="px-3 py-3 text-right text-ink">{{ trip.invoice_count > 0 ? `$ ${fmt2(trip.revenue)}` : '—' }}</td>
                <td class="px-3 py-3 text-right text-ink-muted">{{ trip.fuel > 0 ? `$ ${fmt2(trip.fuel)}` : '—' }}</td>
                <td class="px-3 py-3 text-right text-ink-muted">{{ trip.expenses > 0 ? `$ ${fmt2(trip.expenses)}` : '—' }}</td>
                <td class="px-3 py-3 text-right text-ink-muted">{{ trip.other > 0 ? `$ ${fmt2(trip.other)}` : '—' }}</td>
                <td class="px-3 py-3 text-right text-ink-muted">{{ trip.driver_cost !== null ? `$ ${fmt2(trip.driver_cost)}` : '—' }}</td>
                <td class="px-3 py-3 text-right font-semibold text-ink">{{ trip.analyzable ? `$ ${fmt2(trip.direct_cost)}` : '—' }}</td>
                <td class="px-3 py-3 text-right font-bold" :class="marginTone(trip.margin)">{{ trip.analyzable ? `$ ${fmt2(trip.margin)}` : 'Sin datos' }}</td>
                <td class="px-3 py-3 text-right font-semibold" :class="marginTone(trip.margin)">{{ trip.analyzable ? fmtPct(trip.margin_pct) : '—' }}</td>
                <td class="px-3 py-3"><span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold" :class="qualityTone[trip.quality] ?? 'bg-surface-muted text-ink-muted'">{{ trip.quality_label }}</span></td>
                <td class="px-3 py-3 text-right"><a :href="tripUrl(trip.id)" class="font-semibold text-primary hover:text-primary-hover">Ver viaje</a></td>
              </tr>
            </tbody>
          </table>
        </div>
        <nav v-if="pagination.pages > 1" aria-label="Paginación de rentabilidad" class="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-border-subtle pt-4">
          <p class="text-xs text-ink-muted">Página {{ pagination.page }} de {{ pagination.pages }} · {{ pagination.total }} viajes</p>
          <div class="flex gap-2">
            <a :href="pageUrl(pagination.page - 1)" :class="[secondaryButton, pagination.page <= 1 ? 'pointer-events-none opacity-50' : '']">Anterior</a>
            <a :href="pageUrl(pagination.page + 1)" :class="[secondaryButton, pagination.page >= pagination.pages ? 'pointer-events-none opacity-50' : '']">Siguiente</a>
          </div>
        </nav>
      </PanelCard>
    </template>
  </div>
</template>
