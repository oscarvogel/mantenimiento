<script setup>
import { primaryButton, secondaryButton } from '../../ui/buttons.js'
import { computed } from 'vue'
import { ArrowDownTrayIcon, ArrowLeftIcon, ChartBarSquareIcon, HomeIcon } from '@heroicons/vue/24/outline'

const props = defineProps({
  data: { type: Object, required: true },
})

const report = computed(() => props.data.report ?? {})
const urls = computed(() => props.data.urls ?? {})
const portalUrl = computed(() => props.data.portalUrl ?? '')
const summary = computed(() => report.value.summary ?? {})
const rows = computed(() => report.value.equipment ?? [])
const filters = computed(() => report.value.filters ?? {})
const tolerances = computed(() => report.value.tolerances ?? {})
const branches = computed(() => report.value.branches ?? [])

const number = new Intl.NumberFormat('es-AR', { maximumFractionDigits: 0 })
const decimal = new Intl.NumberFormat('es-AR', { maximumFractionDigits: 1 })
const date = new Intl.DateTimeFormat('es-AR', { day: '2-digit', month: 'short', year: 'numeric' })

const km = (value) => value === null || value === undefined ? '—' : `${number.format(Number(value))} km`
const percent = (value) => value === null || value === undefined ? '—' : `${decimal.format(Number(value))}%`
const signedKm = (value) => value === null || value === undefined
  ? '—'
  : `${Number(value) > 0 ? '+' : ''}${decimal.format(Number(value))} km`
const dateLabel = (value) => {
  if (!value) return '—'
  const parsed = new Date(String(value).replace(' ', 'T'))
  return Number.isNaN(parsed.getTime()) ? '—' : date.format(parsed)
}

const verdictTone = (verdict) => ({
  CONFIABLE: 'bg-success-subtle text-success-strong',
  REVISAR: 'bg-warning-subtle text-warning-strong',
  NO_CONFIABLE: 'bg-danger-subtle text-danger-strong',
  MUESTRA_INSUFICIENTE: 'bg-info-subtle text-info-strong',
  SIN_COBERTURA: 'bg-surface-muted text-ink-muted',
  SIN_DATOS: 'bg-surface-muted text-ink-muted',
}[verdict] ?? 'bg-surface-muted text-ink-muted')

const branchLabel = computed(() => {
  const branch = branches.value.find((item) => Number(item.id) === Number(filters.value.branchId))
  return branch?.nombre ?? 'Todas las autorizadas'
})
const filterSummary = computed(() => `${branchLabel.value} · ${dateLabel(filters.value.from)}–${dateLabel(filters.value.to)}`)

const hasRows = computed(() => rows.value.length > 0)
const summaryCards = computed(() => [
  { label: 'Equipos con lecturas', value: number.format(summary.value.equipmentWithDriverReadings ?? 0), hint: 'Choferes en el período' },
  { label: 'Equipos comparables', value: number.format(summary.value.equipmentComparable ?? 0), hint: 'Con al menos un tramo medido' },
  { label: 'Tramos comparables', value: number.format(summary.value.intervalsComparable ?? 0), hint: 'Pares de lecturas con telemetría' },
  { label: 'Dentro de tolerancia', value: percent(summary.value.withinPercent), hint: `El mayor entre ±${number.format(tolerances.value.toleranceKm ?? 0)} km y ${decimal.format(tolerances.value.tolerancePercent ?? 0)}% del tramo` },
  { label: 'Error mediano', value: km(summary.value.medianAbsoluteErrorKm), hint: 'Diferencia entre incrementos' },
  { label: 'Cobertura', value: percent(summary.value.coveragePercent), hint: 'Tramos con muestra cercana' },
])
</script>

<template>
  <div>
    <header class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
      <div>
        <p class="flex items-center gap-2 text-sm font-semibold text-primary">
          <ChartBarSquareIcon class="size-5" aria-hidden="true" />Reportes · Telemetría
        </p>
        <h1 class="mt-1 text-2xl font-bold tracking-tight text-ink sm:text-3xl">Confiabilidad de telemetría</h1>
        <p class="mt-2 max-w-3xl text-sm leading-6 text-ink-muted">
          Compara los kilómetros que informa el proveedor con los que cargan los choferes. La diferencia se calcula por
          <strong class="font-semibold text-ink">tramo recorrido</strong>, no por odómetro absoluto.
        </p>
      </div>
      <div class="flex flex-wrap items-center gap-2 self-start sm:self-auto">
        <a
          v-if="portalUrl"
          :href="portalUrl"
          
         :class="secondaryButton">
          <HomeIcon class="size-5" aria-hidden="true" />Volver al inicio
        </a>
        <a
          v-if="urls.index"
          :href="urls.index"
          
         :class="secondaryButton">
          <ArrowLeftIcon class="size-5" aria-hidden="true" />Reportes
        </a>
        <a
          v-if="urls.export"
          :href="urls.export"
          
         :class="secondaryButton">
          <ArrowDownTrayIcon class="size-5" aria-hidden="true" />Exportar CSV
        </a>
      </div>
    </header>

    <details class="group mt-6 rounded-card bg-surface-raised shadow-card" open>
      <summary class="flex min-h-14 cursor-pointer list-none items-center justify-between gap-3 px-4 py-3 marker:hidden sm:px-5">
        <span class="min-w-0">
          <span class="block text-sm font-semibold text-ink">Período y sucursal</span>
          <span class="block truncate text-xs text-ink-muted">{{ filterSummary }}</span>
        </span>
        <span class="text-sm font-semibold text-primary group-open:hidden">Editar</span>
        <span class="hidden text-sm font-semibold text-primary group-open:inline">Cerrar</span>
      </summary>
      <form
        :action="urls.index"
        method="get"
        class="grid gap-4 border-t border-border-subtle p-4 sm:grid-cols-2 xl:grid-cols-[minmax(12rem,1fr)_repeat(2,minmax(9rem,0.7fr))_auto] xl:items-end"
      >
        <label class="grid gap-1.5 text-sm font-semibold text-ink">
          Sucursal
          <select name="sucursal_id" class="min-h-11 rounded-pill border border-transparent bg-surface-muted px-3 text-sm font-normal text-ink">
            <option value="">Todas las autorizadas</option>
            <option
              v-for="branch in branches"
              :key="branch.id"
              :value="branch.id"
              :selected="Number(filters.branchId) === Number(branch.id)"
            >{{ branch.nombre }}</option>
          </select>
        </label>
        <label class="grid gap-1.5 text-sm font-semibold text-ink">
          Desde
          <input type="date" name="desde" :value="filters.from" required class="min-h-11 rounded-pill border border-transparent bg-surface-muted px-3 text-sm font-normal text-ink" />
        </label>
        <label class="grid gap-1.5 text-sm font-semibold text-ink">
          Hasta
          <input type="date" name="hasta" :value="filters.to" required class="min-h-11 rounded-pill border border-transparent bg-surface-muted px-3 text-sm font-normal text-ink" />
        </label>
        <button type="submit" :class="primaryButton">
          Aplicar
        </button>
      </form>
      <p class="border-t border-border-subtle px-4 py-3 text-xs leading-5 text-ink-muted sm:px-5">
        Se admiten hasta 90 días porque las muestras de telemetría se purgan. Una lectura se empareja con la muestra más
        cercana dentro de {{ tolerances.matchWindowMinutes ?? 90 }} minutos; si no hay muestra en alguno de los extremos,
        el tramo cuenta como falta de cobertura y no como error.
      </p>
    </details>

    <section aria-labelledby="reliability-summary-title" class="mt-6">
      <h2 id="reliability-summary-title" class="sr-only">Resumen del período</h2>
      <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-6">
        <article v-for="card in summaryCards" :key="card.label" class="rounded-card bg-surface-raised p-4 shadow-card">
          <p class="text-xs font-bold uppercase tracking-wide text-ink-muted">{{ card.label }}</p>
          <p class="mt-1 text-2xl font-bold tabular-nums text-ink">{{ card.value }}</p>
          <p class="mt-1 text-xs leading-5 text-ink-muted">{{ card.hint }}</p>
        </article>
      </div>
    </section>

    <section class="mt-6 overflow-hidden rounded-card bg-surface-raised shadow-card" aria-labelledby="reliability-table-title">
      <div class="border-b border-border px-5 py-4 sm:px-6">
        <h2 id="reliability-table-title" class="text-lg font-bold text-ink">Detalle por equipo</h2>
        <p class="mt-1 text-sm leading-6 text-ink-muted">
          «Confiable» exige al menos {{ 5 }} tramos comparables, {{ percent(90) }} dentro de tolerancia y
          {{ percent(80) }} de cobertura. Es un umbral conservador para decidir si se puede dejar de pedir la lectura.
        </p>
      </div>

      <div v-if="hasRows" class="overflow-x-auto">
        <table class="ui-table min-w-full divide-y divide-border text-left text-sm">
          <thead class="bg-surface-muted text-xs font-semibold uppercase tracking-wide text-ink-muted">
            <tr>
              <th scope="col" class="px-4 py-3">Equipo</th>
              <th scope="col" class="px-4 py-3">Sucursal</th>
              <th scope="col" class="px-4 py-3">Fuente</th>
              <th scope="col" class="px-4 py-3 text-right">Lecturas</th>
              <th scope="col" class="px-4 py-3 text-right">Tramos</th>
              <th scope="col" class="px-4 py-3 text-right">Cobertura</th>
              <th scope="col" class="px-4 py-3 text-right">Dentro tol.</th>
              <th scope="col" class="px-4 py-3 text-right">Error mediano</th>
              <th scope="col" class="px-4 py-3 text-right">Error máx.</th>
              <th scope="col" class="px-4 py-3 text-right">Km chofer</th>
              <th scope="col" class="px-4 py-3 text-right">Km telemetría</th>
              <th scope="col" class="px-4 py-3">Veredicto</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border-subtle">
            <tr v-for="row in rows" :key="`${row.equipmentId}-${row.integrationId ?? 'none'}`" class="hover:bg-surface-subtle">
              <th scope="row" class="whitespace-nowrap px-4 py-3 font-semibold text-ink">
                {{ row.plate && row.plate !== row.equipmentCode ? `${row.plate} · ${row.equipmentCode}` : row.equipmentCode }}
                <span class="block text-xs font-normal text-ink-muted">Última lectura: {{ dateLabel(row.lastReadingAt) }}</span>
              </th>
              <td class="whitespace-nowrap px-4 py-3 text-ink-muted">{{ row.branchName ?? '—' }}</td>
              <td class="whitespace-nowrap px-4 py-3 text-ink-muted">{{ row.integrationName }}</td>
              <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums text-ink">{{ number.format(row.driverReadings) }}</td>
              <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums text-ink">{{ number.format(row.intervalsComparable) }}</td>
              <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums text-ink-muted">{{ percent(row.coveragePercent) }}</td>
              <td class="whitespace-nowrap px-4 py-3 text-right font-semibold tabular-nums text-ink">{{ percent(row.withinPercent) }}</td>
              <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums text-ink">{{ km(row.medianAbsoluteErrorKm) }}</td>
              <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums text-ink-muted">{{ km(row.maxAbsoluteErrorKm) }}</td>
              <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums text-ink">{{ km(row.driverKm) }}</td>
              <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums text-ink">{{ km(row.telemetryKm) }}</td>
              <td class="whitespace-nowrap px-4 py-3">
                <span class="rounded-full px-2.5 py-1 text-xs font-semibold" :class="verdictTone(row.verdict)">
                  {{ row.verdictLabel }}
                </span>
                <span v-if="row.meanSignedErrorKm !== null && row.meanSignedErrorKm !== undefined" class="mt-1 block text-xs text-ink-muted">
                  Sesgo: {{ signedKm(row.meanSignedErrorKm) }}
                </span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <p v-else role="status" class="px-5 py-10 text-center text-sm leading-6 text-ink-muted">
        No hay lecturas de chofer ni muestras de telemetría para el período y la sucursal seleccionados.
      </p>
    </section>

    <p class="mt-4 text-xs leading-5 text-ink-muted">
      Hasta tanto se adopte una decisión, el odómetro vigente del sistema sigue siendo el que confirma el chofer.
      Este informe no modifica lecturas ni planes.
    </p>
  </div>
</template>
