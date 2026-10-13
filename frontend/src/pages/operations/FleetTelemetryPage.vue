<script setup>
import { computed, defineAsyncComponent, ref } from 'vue'
import { ExclamationTriangleIcon, MapPinIcon, MagnifyingGlassIcon } from '@heroicons/vue/24/outline'

const FleetTelemetryMap = defineAsyncComponent({
  loader: () => import('./components/FleetTelemetryMap.vue'),
  loadingComponent: { template: '<div class="min-h-80 bg-surface-muted sm:min-h-[26rem]" aria-label="Cargando mapa" />' },
  delay: 0,
})

const props = defineProps({ data: { type: Object, required: true } })
const search = ref('')
const branch = ref('')
const status = ref('')
const issuesOnly = ref(false)
const diagnosticEquipment = ref(null)
const diagnosticDate = ref(props.data.today ?? new Date().toLocaleDateString('en-CA'))
const diagnosticData = ref(null)
const diagnosticLoading = ref(false)
const diagnosticError = ref('')
let diagnosticRequest = 0
const freshnessNames = {
  AL_DIA: 'Al día', RECIENTE: 'Reciente', DESACTUALIZADO: 'Desactualizado',
  SIN_RESPUESTA: 'Sin respuesta', SIN_DATO: 'Sin señal',
}
const freshnessTone = {
  AL_DIA: 'bg-success-subtle text-success-strong',
  RECIENTE: 'bg-info-subtle text-info-strong',
  DESACTUALIZADO: 'bg-warning-subtle text-warning-strong',
  SIN_RESPUESTA: 'bg-danger-subtle text-danger-strong',
  SIN_DATO: 'bg-surface-muted text-ink-muted',
}
const sourceFor = (unit) => unit.sources?.find((source) => source.role === 'PRINCIPAL') ?? unit.sources?.[0] ?? null
const sourceStatus = (unit) => sourceFor(unit)?.freshness ?? 'SIN_DATO'
const hasValidPosition = (source) => {
  const latitude = Number(source?.position?.latitude)
  const longitude = Number(source?.position?.longitude)
  return source?.position?.latitude !== null && source?.position?.latitude !== undefined
    && source?.position?.longitude !== null && source?.position?.longitude !== undefined
    && Number.isFinite(latitude) && latitude >= -90 && latitude <= 90
    && Number.isFinite(longitude) && longitude >= -180 && longitude <= 180
}
const unitHasPosition = (unit) => (unit.sources ?? []).some(hasValidPosition)
const hasIssues = (unit) => (unit.sources ?? []).some((source) => source.sensorIssues?.length)
const issues = computed(() => (props.data.units ?? []).flatMap((unit) => (unit.sources ?? []).flatMap((source) =>
  (source.sensorIssues ?? []).map((issue) => ({
    equipmentId: unit.equipmentId,
    code: unit.plate || unit.code,
    detailUrl: unit.detailUrl,
    integration: source.integrationName || source.provider,
    sensor: issue.sensor || 'Sensor',
    value: issue.valor === null || issue.valor === undefined ? '' : ` (${new Intl.NumberFormat('es-AR', { maximumFractionDigits: 2 }).format(issue.valor)})`,
    reason: issue.motivo || 'requiere revisión',
  })),
)))
const issueEquipmentCount = computed(() => new Set(issues.value.map((issue) => issue.equipmentId)).size)
const units = computed(() => props.data.units ?? [])
const signalNeedsAttentionCount = computed(() => units.value.filter((unit) => ['DESACTUALIZADO', 'SIN_RESPUESTA', 'SIN_DATO'].includes(sourceStatus(unit))).length)
const filteredUnits = computed(() => {
  const query = search.value.trim().toLocaleLowerCase('es-AR')
  return (props.data.units ?? []).filter((unit) => {
    const textMatch = !query || [unit.code, unit.plate, unit.branchName].filter(Boolean).join(' ').toLocaleLowerCase('es-AR').includes(query)
    const branchMatch = !branch.value || String(unit.branchId) === branch.value
    const statusMatch = !status.value || sourceStatus(unit) === status.value
    const issueMatch = !issuesOnly.value || hasIssues(unit)
    return textMatch && branchMatch && statusMatch && issueMatch
  })
})
const locatedUnits = computed(() => filteredUnits.value.filter(unitHasPosition))
const fleetStatusCount = (value) => (props.data.units ?? []).filter((unit) => sourceStatus(unit) === value).length
const signalSummary = computed(() => Object.entries(freshnessNames)
  .map(([state, label]) => ({ state, count: fleetStatusCount(state), label }))
  .filter((item) => item.count > 0)
  .map(({ state, count }) => ({
    state,
    text: state === 'AL_DIA' ? `${count} al día`
      : state === 'RECIENTE' ? `${count} ${count === 1 ? 'reciente' : 'recientes'}`
        : state === 'DESACTUALIZADO' ? `${count} ${count === 1 ? 'atrasado' : 'atrasados'}`
          : state === 'SIN_RESPUESTA' ? `${count} sin respuesta`
            : `${count} sin señal`,
  })))
const formatNumber = (value) => value === null || value === undefined ? '—' : new Intl.NumberFormat('es-AR', { maximumFractionDigits: 1 }).format(value)
const valueLabel = (value, unit) => value === null || value === undefined ? 'Sin dato' : `${formatNumber(value)} ${unit}`
const engineLabel = (source) => source?.engineOn === true ? 'Encendido' : source?.engineOn === false ? 'Apagado' : 'Sin dato'
const idlingLabel = (source) => source?.idling === true ? 'Activo' : source?.idling === false ? 'Sin ralentí' : 'Sin dato'
const speedLabel = (source) => valueLabel(source?.position?.speedKmh, 'km/h')
const observedLabel = (source) => {
  if (!source?.observedAt) return 'Sin señal registrada'
  if (source.ageMinutes === 0) return 'Ahora'
  return `Hace ${source.ageMinutes} min`
}
const openDiagnostic = (unit) => {
  diagnosticRequest++
  diagnosticLoading.value = false
  diagnosticEquipment.value = unit
  diagnosticData.value = null
  diagnosticError.value = ''
}
const closeDiagnostic = () => {
  diagnosticRequest++
  diagnosticLoading.value = false
  diagnosticEquipment.value = null
  diagnosticData.value = null
  diagnosticError.value = ''
}
const loadDiagnostic = async () => {
  if (!diagnosticEquipment.value || !diagnosticDate.value || !props.data.diagnosticUrl) return
  const requestId = ++diagnosticRequest
  diagnosticLoading.value = true
  diagnosticData.value = null
  diagnosticError.value = ''

  try {
    const query = new URLSearchParams({ equipo: String(diagnosticEquipment.value.equipmentId), fecha: diagnosticDate.value })
    const response = await fetch(`${props.data.diagnosticUrl}?${query}`, {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    })
    const payload = await response.json().catch(() => null)
    if (requestId !== diagnosticRequest) return
    if (!response.ok) throw new Error(payload?.message || 'No se pudo cargar el diagnóstico.')
    diagnosticData.value = payload
  } catch (error) {
    if (requestId === diagnosticRequest) {
      diagnosticError.value = error instanceof Error ? error.message : 'No se pudo cargar el diagnóstico. Intentá de nuevo.'
    }
  } finally {
    if (requestId === diagnosticRequest) diagnosticLoading.value = false
  }
}
const observedName = (sample) => String(sample.provider).toLowerCase() === 'wialon' ? 'Observado por Wialon' : `Observado por ${sample.provider || 'proveedor'}`
const formatDiagnosticValue = (value, unit) => value === null || value === undefined ? '—' : `${formatNumber(value)} ${unit}`
</script>

<template>
  <main class="space-y-6">
    <header class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
      <div>
        <p class="text-sm font-semibold uppercase tracking-wide text-primary">Operación · Telemetría</p>
        <h1 class="mt-1 text-2xl font-bold tracking-tight text-ink sm:text-3xl">Telemetría de flota</h1>
        <p class="mt-2 text-sm text-ink-muted">Ubicación, señal y lecturas recientes de los equipos vinculados.</p>
      </div>
      <div v-if="signalSummary.length" class="grid gap-1.5 text-xs" aria-label="Estado de la señal">
        <span class="font-semibold text-ink-muted">Estado de la señal</span>
        <div class="flex flex-wrap gap-2 font-semibold">
          <span v-for="item in signalSummary" :key="item.state" class="rounded-full px-3 py-1.5" :class="freshnessTone[item.state]">{{ item.text }}</span>
        </div>
      </div>
    </header>

    <section class="grid grid-cols-2 gap-3 xl:grid-cols-4" aria-label="Indicadores de telemetría">
      <article class="rounded-xl border border-border bg-surface-raised px-4 py-3 shadow-card"><p class="text-xs font-semibold uppercase tracking-wide text-ink-muted">Equipos vinculados</p><p class="mt-1 text-2xl font-bold tabular-nums text-ink">{{ data.units?.length ?? 0 }}</p></article>
      <article class="rounded-xl border border-border bg-surface-raised px-4 py-3 shadow-card"><p class="text-xs font-semibold uppercase tracking-wide text-ink-muted">Con ubicación</p><p class="mt-1 text-2xl font-bold tabular-nums text-ink">{{ (data.units ?? []).filter(unitHasPosition).length }}</p></article>
      <article class="rounded-xl border border-border bg-surface-raised px-4 py-3 shadow-card"><p class="text-xs font-semibold uppercase tracking-wide text-ink-muted">Señal atrasada o ausente</p><p class="mt-1 text-2xl font-bold tabular-nums text-ink">{{ signalNeedsAttentionCount }}</p></article>
      <article class="rounded-xl border border-border bg-surface-raised px-4 py-3 shadow-card"><p class="text-xs font-semibold uppercase tracking-wide text-ink-muted">Equipos con sensor a revisar</p><p class="mt-1 text-2xl font-bold tabular-nums text-ink">{{ issueEquipmentCount }}</p></article>
    </section>

    <section class="grid gap-3 rounded-2xl border border-border bg-surface-raised p-4 shadow-card sm:grid-cols-2 xl:grid-cols-[minmax(14rem,1.6fr)_minmax(12rem,1fr)_minmax(12rem,1fr)_auto] xl:items-end" aria-label="Filtros de flota">
      <label class="grid gap-1.5 text-sm font-semibold text-ink">
        Buscar equipo
        <span class="relative"><MagnifyingGlassIcon class="pointer-events-none absolute left-3 top-3 size-5 text-ink-muted" aria-hidden="true" /><input v-model="search" data-test="fleet-search" type="search" class="min-h-11 w-full rounded-lg border border-border-strong bg-surface-raised pl-10 pr-3 text-sm font-normal text-ink" placeholder="Patente o código" /></span>
      </label>
      <label class="grid gap-1.5 text-sm font-semibold text-ink">Sucursal
        <select v-model="branch" class="min-h-11 rounded-lg border border-border-strong bg-surface-raised px-3 text-sm font-normal text-ink"><option value="">Todas las autorizadas</option><option v-for="item in data.branches ?? []" :key="item.id" :value="String(item.id)">{{ item.name }}</option></select>
      </label>
      <label class="grid gap-1.5 text-sm font-semibold text-ink">Señal
        <select v-model="status" class="min-h-11 rounded-lg border border-border-strong bg-surface-raised px-3 text-sm font-normal text-ink"><option value="">Todos los estados</option><option v-for="(label, key) in freshnessNames" :key="key" :value="key">{{ label }}</option></select>
      </label>
      <label class="flex min-h-11 items-center gap-2 rounded-lg border border-border px-3 text-sm font-semibold text-ink"><input v-model="issuesOnly" data-test="issues-only" type="checkbox" class="size-4 accent-primary" />Solo sensores con problemas</label>
    </section>

    <div class="grid gap-5 xl:grid-cols-[minmax(0,1.8fr)_minmax(19rem,0.8fr)]">
      <section class="overflow-hidden rounded-2xl border border-border bg-surface-raised shadow-card">
        <div class="flex flex-wrap items-start justify-between gap-3 border-b border-border-subtle px-5 py-4">
          <div><h2 class="text-base font-bold text-ink">Ubicación de la flota</h2><p class="mt-1 text-sm text-ink-muted">{{ locatedUnits.length }} de {{ filteredUnits.length }} equipos con ubicación disponible</p></div>
          <div class="flex flex-wrap gap-x-4 gap-y-2 text-xs text-ink-muted"><span class="inline-flex items-center gap-1.5"><i class="size-2.5 rounded-full bg-green-500" />Al día</span><span class="inline-flex items-center gap-1.5"><i class="size-2.5 rounded-full bg-amber-500" />Sin respuesta</span><span class="inline-flex items-center gap-1.5"><i class="size-2.5 rounded-full bg-red-500" />Sensor a revisar</span></div>
        </div>
        <FleetTelemetryMap :units="locatedUnits" />
        <p v-if="locatedUnits.length === 0" class="flex items-center gap-2 border-t border-border-subtle px-5 py-3 text-sm text-ink-muted"><MapPinIcon class="size-5 shrink-0" aria-hidden="true" />{{ data.units?.length ? 'Todavía no hay equipos con ubicación disponible para mostrar.' : 'No hay equipos vinculados con un proveedor de telemetría activo.' }}</p>
        <p class="px-5 pb-3 pt-1 text-xs text-ink-muted">Ubicaciones enviadas por el proveedor de telemetría · © OpenStreetMap</p>
      </section>

      <aside class="rounded-2xl border border-border bg-surface-raised p-5 shadow-card" aria-labelledby="sensor-issues-title">
        <div class="flex items-start gap-3">
          <span class="flex size-10 shrink-0 items-center justify-center rounded-xl" :class="issues.length ? 'bg-warning-subtle text-warning-strong' : 'bg-success-subtle text-success-strong'"><ExclamationTriangleIcon class="size-5" aria-hidden="true" /></span>
          <div><h2 id="sensor-issues-title" class="text-base font-bold text-ink">Sensores con problemas</h2><p class="mt-1 text-sm text-ink-muted">{{ issueEquipmentCount }} {{ issueEquipmentCount === 1 ? 'equipo con sensores a revisar' : 'equipos con sensores a revisar' }}</p></div>
        </div>
        <p class="mt-4 rounded-lg bg-surface-muted px-3 py-2 text-xs leading-5 text-ink-muted">Las alertas se incluyen en las notificaciones diarias.</p>
        <ul v-if="issues.length" class="mt-4 divide-y divide-border-subtle">
          <li v-for="(issue, index) in issues.slice(0, 8)" :key="`${issue.equipmentId}-${issue.sensor}-${index}`" class="py-3 first:pt-0">
            <a :href="issue.detailUrl" class="font-semibold text-ink hover:text-primary">{{ issue.code }}</a>
            <p class="mt-1 text-sm text-ink-muted">{{ issue.sensor }}{{ issue.value }} · {{ issue.reason }}</p>
            <p class="mt-1 text-xs text-ink-muted">{{ issue.integration }}</p>
          </li>
        </ul>
        <div v-else class="mt-5 rounded-xl border border-dashed border-border-strong px-4 py-6 text-center"><p class="text-sm font-semibold text-ink">Sin anomalías de sensores</p><p class="mt-1 text-xs text-ink-muted">No se detectaron valores de sensores fuera de rango.</p></div>
        <p v-if="issues.length > 8" class="mt-3 text-xs text-ink-muted">Y {{ issues.length - 8 }} alertas más.</p>
      </aside>
    </div>

    <section class="overflow-hidden rounded-2xl border border-border bg-surface-raised shadow-card">
      <div class="flex items-end justify-between gap-3 border-b border-border-subtle px-5 py-4"><div><h2 class="text-base font-bold text-ink">Equipos</h2><p class="mt-1 text-sm text-ink-muted">Lectura más reciente por equipo y proveedor principal</p></div><span class="rounded-full bg-surface-muted px-3 py-1 text-xs font-semibold text-ink-muted">{{ filteredUnits.length }}</span></div>
      <div v-if="filteredUnits.length" class="hidden overflow-x-auto lg:block" data-test="fleet-desktop-table">
        <table class="w-full min-w-[1050px] border-collapse text-left">
          <thead class="bg-surface-muted text-xs font-semibold uppercase tracking-wide text-ink-muted"><tr><th scope="col" class="px-4 py-3">Equipo</th><th scope="col" class="px-4 py-3">Combustible</th><th scope="col" class="px-4 py-3">Kilometraje</th><th scope="col" class="px-4 py-3">Horómetro</th><th scope="col" class="px-4 py-3">Motor / ralentí</th><th scope="col" class="px-4 py-3">Batería</th><th scope="col" class="px-4 py-3">Velocidad</th><th scope="col" class="px-4 py-3">Señal</th><th scope="col" class="px-4 py-3">Revisión</th></tr></thead>
          <tbody class="divide-y divide-border-subtle">
            <tr v-for="unit in filteredUnits" :key="unit.equipmentId" class="align-middle">
              <th scope="row" class="min-w-44 px-4 py-3"><a :href="unit.detailUrl" class="font-bold text-ink hover:text-primary">{{ unit.plate || unit.code }}</a><p v-if="unit.plate && unit.plate !== unit.code" class="mt-0.5 text-xs font-normal text-ink-muted">{{ unit.code }}</p><p class="mt-1 text-xs font-normal text-ink-muted">{{ unit.branchName }}</p><p v-if="data.canViewDrivers" class="mt-1 text-xs font-normal text-ink-muted"><span class="font-semibold">Chofer:</span> {{ unit.driverName || 'Sin chofer asignado' }}</p></th>
              <td class="whitespace-nowrap px-4 py-3 text-sm font-semibold text-ink">{{ valueLabel(sourceFor(unit)?.fuelLiters, 'l') }}</td>
              <td class="whitespace-nowrap px-4 py-3 text-sm font-semibold text-ink">{{ valueLabel(sourceFor(unit)?.kilometers, 'km') }}</td>
              <td class="whitespace-nowrap px-4 py-3 text-sm font-semibold text-ink">{{ valueLabel(sourceFor(unit)?.hours, 'h') }}</td>
              <td class="whitespace-nowrap px-4 py-3"><p class="text-sm font-semibold text-ink">{{ engineLabel(sourceFor(unit)) }}</p><p class="mt-0.5 text-xs text-ink-muted">Ralentí: {{ idlingLabel(sourceFor(unit)) }}</p></td>
              <td class="whitespace-nowrap px-4 py-3 text-sm font-semibold text-ink">{{ valueLabel(sourceFor(unit)?.voltage, 'V') }}</td>
              <td class="whitespace-nowrap px-4 py-3 text-sm font-semibold text-ink">{{ speedLabel(sourceFor(unit)) }}</td>
              <td class="min-w-36 px-4 py-3"><span class="rounded-full px-2.5 py-1 text-xs font-semibold" :class="freshnessTone[sourceStatus(unit)]">{{ freshnessNames[sourceStatus(unit)] }}</span><p class="mt-1.5 text-xs text-ink-muted">{{ observedLabel(sourceFor(unit)) }}</p></td>
              <td class="whitespace-nowrap px-4 py-3"><button type="button" class="rounded-lg border border-primary px-3 py-2 text-xs font-semibold text-primary hover:bg-primary-subtle" :data-test="`diagnostic-open-${unit.equipmentId}`" @click="openDiagnostic(unit)">Revisar lecturas</button></td>
            </tr>
          </tbody>
        </table>
      </div>
      <div v-if="filteredUnits.length" class="divide-y divide-border-subtle lg:hidden" data-test="fleet-mobile-cards">
        <article v-for="unit in filteredUnits" :key="unit.equipmentId" class="px-4 py-4">
          <div class="flex items-start justify-between gap-3"><div class="min-w-0"><a :href="unit.detailUrl" class="font-bold text-ink hover:text-primary">{{ unit.plate || unit.code }}</a><p v-if="unit.plate && unit.plate !== unit.code" class="mt-0.5 text-xs text-ink-muted">{{ unit.code }}</p><p class="mt-1 text-xs text-ink-muted">{{ unit.branchName }}</p><p v-if="data.canViewDrivers" class="mt-1 text-xs text-ink-muted"><span class="font-semibold">Chofer:</span> {{ unit.driverName || 'Sin chofer asignado' }}</p></div><span class="shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold" :class="freshnessTone[sourceStatus(unit)]">{{ freshnessNames[sourceStatus(unit)] }}</span></div>
          <div class="mt-4 grid grid-cols-2 gap-x-4 gap-y-3 rounded-lg bg-surface-muted/60 p-3">
            <div><p class="text-xs text-ink-muted">Combustible</p><p class="mt-0.5 text-sm font-semibold text-ink">{{ valueLabel(sourceFor(unit)?.fuelLiters, 'l') }}</p></div>
            <div><p class="text-xs text-ink-muted">Kilometraje</p><p class="mt-0.5 text-sm font-semibold text-ink">{{ valueLabel(sourceFor(unit)?.kilometers, 'km') }}</p></div>
            <div><p class="text-xs text-ink-muted">Motor</p><p class="mt-0.5 text-sm font-semibold text-ink">{{ engineLabel(sourceFor(unit)) }}</p></div>
            <div><p class="text-xs text-ink-muted">Batería</p><p class="mt-0.5 text-sm font-semibold text-ink">{{ valueLabel(sourceFor(unit)?.voltage, 'V') }}</p></div>
            <div><p class="text-xs text-ink-muted">Horómetro</p><p class="mt-0.5 text-sm font-semibold text-ink">{{ valueLabel(sourceFor(unit)?.hours, 'h') }}</p></div>
            <div><p class="text-xs text-ink-muted">Velocidad</p><p class="mt-0.5 text-sm font-semibold text-ink">{{ speedLabel(sourceFor(unit)) }}</p></div>
          </div>
          <div class="mt-3 flex flex-wrap items-center justify-between gap-2 text-xs text-ink-muted"><span>Ralentí: {{ idlingLabel(sourceFor(unit)) }}</span><span>{{ observedLabel(sourceFor(unit)) }}</span><button type="button" class="rounded-lg border border-primary px-3 py-2 font-semibold text-primary" :data-test="`diagnostic-open-${unit.equipmentId}`" @click="openDiagnostic(unit)">Revisar lecturas</button></div>
        </article>
      </div>
      <p v-else class="px-5 py-8 text-center text-sm text-ink-muted">No hay equipos que coincidan con estos filtros.</p>
    </section>

    <section v-if="diagnosticEquipment" class="overflow-hidden rounded-2xl border border-border bg-surface-raised shadow-card" data-test="diagnostic-panel" aria-labelledby="fleet-diagnostic-title">
      <div class="flex flex-wrap items-start justify-between gap-3 border-b border-border-subtle px-5 py-4">
        <div><h2 id="fleet-diagnostic-title" class="text-base font-bold text-ink">Diagnóstico de {{ diagnosticEquipment.plate || diagnosticEquipment.code }}</h2><p class="mt-1 text-sm text-ink-muted">Capturas guardadas y viajes reconstruidos para comparar en un mismo día.</p></div>
        <button type="button" class="rounded-lg border border-border px-3 py-2 text-sm font-semibold text-ink-muted hover:bg-surface-muted" @click="closeDiagnostic">Cerrar</button>
      </div>
      <div class="flex flex-col gap-3 border-b border-border-subtle p-5 sm:flex-row sm:items-end">
        <label class="grid gap-1.5 text-sm font-semibold text-ink">Día a revisar<input v-model="diagnosticDate" type="date" :max="data.today" data-test="diagnostic-date" class="min-h-11 rounded-lg border border-border-strong bg-surface-raised px-3 text-sm font-normal" /></label>
        <button type="button" data-test="diagnostic-load" :disabled="diagnosticLoading || !diagnosticDate" class="min-h-11 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white disabled:cursor-wait disabled:opacity-60" @click="loadDiagnostic">{{ diagnosticLoading ? 'Cargando…' : 'Cargar día' }}</button>
      </div>
      <p v-if="diagnosticError" role="alert" class="mx-5 mt-4 rounded-lg border border-danger bg-danger-subtle p-3 text-sm text-danger-strong">{{ diagnosticError }}</p>
      <div v-if="diagnosticData" class="space-y-5 p-5" data-test="diagnostic-result">
        <p class="rounded-lg border border-warning bg-warning-subtle p-3 text-sm text-warning-strong">Son capturas guardadas y viajes derivados del histórico. Esta comparación ayuda a encontrar diferencias; no valida por sí sola la exactitud del proveedor ni actualiza el kilometraje operativo.</p>
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4" aria-label="Resumen del diagnóstico">
          <article class="rounded-xl border border-border bg-surface-subtle/50 p-3"><p class="text-xs font-semibold uppercase text-ink-muted">Capturas guardadas</p><p class="mt-1 text-xl font-bold text-ink">{{ diagnosticData.summary.sampleCount }}</p></article>
          <article class="rounded-xl border border-border bg-surface-subtle/50 p-3"><p class="text-xs font-semibold uppercase text-ink-muted">Viajes reconstruidos</p><p class="mt-1 text-xl font-bold text-ink">{{ diagnosticData.summary.tripCount }}</p></article>
          <article class="rounded-xl border border-border bg-surface-subtle/50 p-3"><p class="text-xs font-semibold uppercase text-ink-muted">Cobertura del día</p><p class="mt-1 text-xl font-bold text-ink">{{ diagnosticData.summary.coveragePercent }}%</p></article>
          <article class="rounded-xl border border-border bg-surface-subtle/50 p-3"><p class="text-xs font-semibold uppercase text-ink-muted">Calibración</p><p class="mt-1 text-sm font-bold text-ink">{{ diagnosticData.summary.uncalibrated ? 'Provisoria' : 'Configurada' }}</p></article>
        </div>
        <p v-if="!diagnosticData.summary.coverageSufficient && diagnosticData.summary.coverageReason" class="text-sm text-warning-strong">{{ diagnosticData.summary.coverageReason }}</p>
        <div class="overflow-x-auto rounded-xl border border-border">
          <h3 class="px-4 py-3 text-sm font-bold text-ink">Capturas guardadas</h3>
          <table class="w-full min-w-[62rem] text-left text-xs" data-test="diagnostic-samples">
            <thead class="bg-surface-muted font-semibold uppercase text-ink-muted"><tr><th class="px-3 py-2">Observación del proveedor</th><th class="px-3 py-2">Guardado en el sistema</th><th class="px-3 py-2">Demora</th><th class="px-3 py-2">Combustible</th><th class="px-3 py-2">Kilómetros</th><th class="px-3 py-2">Horómetro</th><th class="px-3 py-2">Velocidad</th><th class="px-3 py-2">Anomalías</th></tr></thead>
            <tbody class="divide-y divide-border-subtle"><tr v-for="(sample, index) in diagnosticData.samples" :key="`${sample.observedAt}-${index}`"><td class="whitespace-nowrap px-3 py-2"><span class="block font-semibold text-ink">{{ observedName(sample) }}</span>{{ sample.observedAt }}</td><td class="whitespace-nowrap px-3 py-2 text-ink">{{ sample.recordedAt }}</td><td class="whitespace-nowrap px-3 py-2 text-ink">{{ sample.delayMinutes }} min</td><td data-test="diagnostic-fuel" class="whitespace-nowrap px-3 py-2 text-ink">{{ formatDiagnosticValue(sample.fuelLiters, 'l') }}</td><td class="whitespace-nowrap px-3 py-2 text-ink">{{ formatDiagnosticValue(sample.kilometers, 'km') }}</td><td class="whitespace-nowrap px-3 py-2 text-ink">{{ formatDiagnosticValue(sample.hours, 'h') }}</td><td class="whitespace-nowrap px-3 py-2 text-ink">{{ formatDiagnosticValue(sample.speedKmh, 'km/h') }}</td><td class="min-w-52 px-3 py-2 text-warning-strong"><p v-for="(anomaly, anomalyIndex) in sample.anomalies" :key="`${anomaly.sensor}-${anomalyIndex}`" :title="anomaly.reason">{{ anomaly.sensor }}{{ anomaly.value === null ? '' : ` (${formatNumber(anomaly.value)})` }}: {{ anomaly.reason }}</p><span v-if="!sample.anomalies?.length">—</span></td></tr></tbody>
          </table>
          <p v-if="!diagnosticData.samples.length" class="px-4 py-4 text-sm text-ink-muted">No hay capturas guardadas para este equipo y día.</p>
        </div>
        <div class="overflow-x-auto rounded-xl border border-border">
          <h3 class="px-4 py-3 text-sm font-bold text-ink">Viajes reconstruidos</h3>
          <table v-if="diagnosticData.trips.length" class="w-full min-w-[36rem] text-left text-xs" data-test="diagnostic-trips"><thead class="bg-surface-muted font-semibold uppercase text-ink-muted"><tr><th class="px-3 py-2">Inicio</th><th class="px-3 py-2">Fin</th><th class="px-3 py-2">km</th><th class="px-3 py-2">Litros</th><th class="px-3 py-2">l/100 km</th><th class="px-3 py-2">Estado</th></tr></thead><tbody class="divide-y divide-border-subtle"><tr v-for="(trip, index) in diagnosticData.trips" :key="trip.start + '-' + index"><td class="whitespace-nowrap px-3 py-2 text-ink">{{ trip.start }}</td><td class="whitespace-nowrap px-3 py-2 text-ink">{{ trip.end }}</td><td class="px-3 py-2 text-ink">{{ formatDiagnosticValue(trip.kilometers, 'km') }}</td><td class="px-3 py-2 text-ink">{{ formatDiagnosticValue(trip.liters, 'l') }}</td><td class="px-3 py-2 text-ink">{{ formatDiagnosticValue(trip.litersPer100Km, 'l/100 km') }}</td><td class="px-3 py-2 text-ink" :title="trip.reason || ''">{{ trip.reconstructible ? 'Reconstruible' : 'No reconstruible' }}</td></tr></tbody></table>
          <p v-else class="px-4 py-4 text-sm text-ink-muted">No se reconstruyeron viajes con las capturas disponibles de este día.</p>
        </div>
      </div>
    </section>
  </main>
</template>
