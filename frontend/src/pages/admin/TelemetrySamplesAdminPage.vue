<script setup>
import { computed } from 'vue'
import { ArrowDownTrayIcon, ArrowPathIcon, CircleStackIcon, ExclamationTriangleIcon } from '@heroicons/vue/24/outline'
import AdminPageHeading from './components/AdminPageHeading.vue'

const props = defineProps({ data: { type: Object, required: true } })
const basePath = window.location.pathname
const pageUrl = (page) => {
  const params = new URLSearchParams(window.location.search)
  params.set('pagina', String(page))
  return `${basePath}?${params.toString()}`
}
const exportUrl = computed(() => {
  const params = new URLSearchParams()
  if (props.data.selectedCompanyId) params.set('empresa', String(props.data.selectedCompanyId))
  if (props.data.selectedIntegrationId) params.set('integracion', String(props.data.selectedIntegrationId))
  params.set('vista', 'samples')
  if (props.data.filters.from) params.set('desde', props.data.filters.from)
  if (props.data.filters.to) params.set('hasta', props.data.filters.to)
  if (props.data.filters.unit) params.set('unidad', props.data.filters.unit)
  return `${basePath.replace(/\/$/, '')}/exportar?${params.toString()}`
})
const firstRow = computed(() => props.data.total === 0 ? 0 : ((props.data.page - 1) * props.data.perPage) + 1)
const lastRow = computed(() => Math.min(props.data.page * props.data.perPage, props.data.total))
const prettyJson = (value) => {
  if (!value) return ''
  try {
    return JSON.stringify(typeof value === 'string' ? JSON.parse(value) : value, null, 2)
  } catch {
    return String(value)
  }
}
const sourceMessageCount = (value) => {
  try {
    const parsed = typeof value === 'string' ? JSON.parse(value) : value
    return Array.isArray(parsed) ? parsed.length : 0
  } catch {
    return 0
  }
}
const exportUrl = computed(() => {
  const params = new URLSearchParams()
  if (props.data.selectedCompanyId) params.set('empresa', String(props.data.selectedCompanyId))
  if (props.data.selectedIntegrationId) params.set('integracion', String(props.data.selectedIntegrationId))
  if (props.data.view) params.set('vista', props.data.view)
  if (props.data.filters.unit) params.set('unidad', props.data.filters.unit)
  if (props.data.filters.from) params.set('desde', props.data.filters.from)
  if (props.data.filters.to) params.set('hasta', props.data.filters.to)
  return `${basePath.replace(/\/$/, '')}/exportar?${params.toString()}`
})
const formatBoolean = (value) => value === null || value === undefined ? '—' : (Number(value) === 1 ? 'Sí' : 'No')
const formatNumber = (value, digits = 1) => value === null || value === undefined
  ? '—'
  : new Intl.NumberFormat('es-AR', { maximumFractionDigits: digits }).format(Number(value))
</script>

<template>
  <main class="space-y-6">
    <AdminPageHeading
      eyebrow="Administración global · Solo lectura"
      title="Datos ingestados de telemetría"
      description="Elegí una empresa y su integración para revisar el histórico guardado. En los intervalos Wialon nuevos también podés comparar cada mensaje original con los valores normalizados."
    />

    <form method="get" :action="basePath" class="rounded-xl border border-border bg-surface-raised p-5 shadow-card sm:p-6">
      <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-6 xl:items-end">
        <label class="space-y-1.5 text-sm font-medium text-ink">
          <span>Empresa</span>
          <select name="empresa" class="min-h-11 w-full rounded-lg border border-border-strong bg-surface-raised px-3 py-2 text-sm text-ink shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/20" @change="$event.target.form.submit()">
            <option v-for="company in data.companies" :key="company.id" :value="company.id" :selected="company.id === data.selectedCompanyId">{{ company.label }}</option>
          </select>
        </label>
        <label class="space-y-1.5 text-sm font-medium text-ink">
          <span>Proveedor de datos</span>
          <select name="integracion" class="min-h-11 w-full rounded-lg border border-border-strong bg-surface-raised px-3 py-2 text-sm text-ink shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/20" :disabled="data.integrations.length === 0">
            <option v-for="integration in data.integrations" :key="integration.id" :value="integration.id" :selected="integration.id === data.selectedIntegrationId">{{ integration.label }}</option>
          </select>
        </label>
        <label class="space-y-1.5 text-sm font-medium text-ink">
          <span>Datos para revisar</span>
          <select name="vista" class="min-h-11 w-full rounded-lg border border-border-strong bg-surface-raised px-3 py-2 text-sm text-ink shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/20">
            <option v-for="view in data.availableViews" :key="view.id" :value="view.id" :selected="view.id === data.view">{{ view.label }}</option>
          </select>
        </label>
        <label class="space-y-1.5 text-sm font-medium text-ink">
          <span>Unidad externa</span>
          <input name="unidad" :value="data.filters.unit" type="search" maxlength="100" placeholder="ID o nombre informado" class="min-h-11 w-full rounded-lg border border-border-strong bg-surface-raised px-3 py-2 text-sm text-ink shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/20" />
        </label>
        <label class="space-y-1.5 text-sm font-medium text-ink">
          <span>Desde</span>
          <input name="desde" :value="data.filters.from" type="date" class="min-h-11 w-full rounded-lg border border-border-strong bg-surface-raised px-3 py-2 text-sm text-ink shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/20" />
        </label>
        <label class="space-y-1.5 text-sm font-medium text-ink">
          <span>Hasta</span>
          <input name="hasta" :value="data.filters.to" type="date" class="min-h-11 w-full rounded-lg border border-border-strong bg-surface-raised px-3 py-2 text-sm text-ink shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/20" />
        </label>
      </div>
      <div class="mt-4 flex flex-wrap items-center gap-3">
        <button type="submit" class="inline-flex min-h-10 items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-strong">
          <ArrowPathIcon class="size-4" aria-hidden="true" /> Aplicar filtros
        </button>
        <a v-if="data.view === 'samples' && data.selectedCompanyId && data.selectedIntegrationId" :href="exportUrl" class="inline-flex min-h-10 items-center rounded-lg border border-primary px-4 py-2 text-sm font-semibold text-primary hover:bg-primary/5">Exportar todas las muestras (CSV)</a>
        <a :href="basePath + (data.selectedCompanyId ? '?empresa=' + data.selectedCompanyId + '&integracion=' + (data.selectedIntegrationId || '') : '')" class="inline-flex min-h-10 items-center rounded-lg border border-border px-4 py-2 text-sm font-semibold text-ink-muted hover:bg-surface-muted">Limpiar fechas y unidad</a>
      </div>
    </form>

    <section v-if="data.selectedIntegration" class="rounded-xl border border-border bg-surface-raised p-5 shadow-card sm:p-6" aria-label="Estado de la integración">
      <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
        <div>
          <p class="text-xs font-bold uppercase tracking-wide text-ink-muted">Integración seleccionada</p>
          <h2 class="mt-1 text-lg font-semibold text-ink">{{ data.selectedIntegration.label }}</h2>
          <p class="mt-1 text-sm text-ink-muted">{{ data.selectedIntegration.active ? 'Activa' : 'Inactiva' }} · última respuesta correcta: {{ data.selectedIntegration.lastSuccess || 'sin registro' }} · fallos consecutivos: {{ data.selectedIntegration.consecutiveFailures }}</p>
          <p v-if="data.selectedIntegration.lastError" class="mt-2 text-sm text-danger-strong">Último error informado: {{ data.selectedIntegration.lastError }}</p>
        </div>
        <div class="grid gap-2 text-sm text-ink-muted sm:grid-cols-2 md:min-w-[34rem] xl:grid-cols-4">
          <div class="rounded-lg bg-surface-subtle p-3"><span class="block text-xs uppercase">Capturas encontradas</span><strong class="mt-1 block text-lg text-ink">{{ data.total.toLocaleString('es-AR') }}</strong></div>
          <div class="rounded-lg bg-surface-subtle p-3"><span class="block text-xs uppercase">Primera observación</span><strong class="mt-1 block text-sm text-ink">{{ data.summary.firstObservedAt || '—' }}</strong></div>
          <div class="rounded-lg bg-surface-subtle p-3"><span class="block text-xs uppercase">Última observación</span><strong class="mt-1 block text-sm text-ink">{{ data.summary.lastObservedAt || '—' }}</strong></div>
          <div class="rounded-lg bg-surface-subtle p-3"><span class="block text-xs uppercase">Última captura guardada</span><strong class="mt-1 block text-sm text-ink">{{ data.summary.lastRecordedAt || '—' }}</strong></div>
        </div>
      </div>
        <p class="mt-4 border-t border-border-subtle pt-3 text-xs text-ink-muted">La hora de observación viene del proveedor. La hora de ingesta indica cuándo se guardó en el sistema. Esta pantalla consulta los datos existentes; no llama al proveedor ni inicia una ingesta.</p>
    </section>

    <section v-if="!data.tableReady" class="flex gap-3 rounded-xl border border-warning/40 bg-warning-subtle p-5 text-warning-strong">
      <ExclamationTriangleIcon class="size-5 shrink-0" aria-hidden="true" />
      <div><h2 class="font-semibold">El histórico todavía no está preparado</h2><p class="mt-1 text-sm">No se encontró la tabla de muestras. Hay que aplicar la migración de telemetría antes de poder consultar capturas.</p></div>
    </section>
    <section v-else-if="data.companies.length === 0" class="rounded-xl border border-border bg-surface-raised p-8 text-center text-sm text-ink-muted shadow-card">No hay empresas para consultar.</section>
    <section v-else-if="data.integrations.length === 0" class="flex gap-3 rounded-xl border border-border bg-surface-raised p-5 text-ink-muted shadow-card">
      <CircleStackIcon class="size-5 shrink-0" aria-hidden="true" />
      <div><h2 class="font-semibold text-ink">Esta empresa no tiene un proveedor configurado</h2><p class="mt-1 text-sm">Elegí otra empresa o revisá la configuración de telemetría de esta cuenta.</p></div>
    </section>
    <section v-else class="overflow-hidden rounded-xl border border-border bg-surface-raised shadow-card">
      <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border-subtle px-5 py-4">
        <div><h2 class="font-semibold text-ink">Capturas crudas</h2><p class="mt-1 text-sm text-ink-muted">{{ data.total ? `Mostrando ${firstRow}–${lastRow} de ${data.total.toLocaleString('es-AR')}` : 'No hay muestras para estos filtros.' }}</p></div>
        <div class="flex flex-wrap items-center gap-2">
          <span class="rounded-full bg-surface-muted px-3 py-1 text-xs font-semibold text-ink-muted">Más recientes primero · {{ data.perPage }} por página</span>
          <a :href="exportUrl" title="Descarga todos los registros que coinciden con los filtros" class="inline-flex min-h-10 items-center gap-2 rounded-lg border border-primary px-3 py-2 text-sm font-semibold text-primary hover:bg-primary-subtle">
            <ArrowDownTrayIcon class="size-4" aria-hidden="true" /> Exportar CSV
          </a>
        </div>
      </div>
      <div v-if="data.view === 'buckets' ? data.buckets.length : data.samples.length" class="overflow-x-auto">
        <table class="min-w-[1250px] w-full text-left text-sm">
          <thead v-if="data.view === 'buckets'" class="bg-surface-subtle text-xs uppercase tracking-wide text-ink-muted"><tr>
            <th class="px-4 py-3">Intervalo UTC</th><th class="px-4 py-3">Unidad / equipo</th><th class="px-4 py-3">Combustible</th><th class="px-4 py-3">Kilometraje / motor</th><th class="px-4 py-3">Ralentí / motor</th><th class="px-4 py-3">Velocidad / posición</th><th class="px-4 py-3">Mensaje original</th>
          </tr></thead>
          <thead v-else class="bg-surface-subtle text-xs uppercase tracking-wide text-ink-muted"><tr>
            <th class="px-4 py-3">Observada / ingresada</th><th class="px-4 py-3">Unidad / equipo</th><th class="px-4 py-3">Posición</th><th class="px-4 py-3">Uso y combustible</th><th class="px-4 py-3">Estado</th><th class="px-4 py-3">Sensores / anomalías</th>
          </tr></thead>
          <tbody class="divide-y divide-border-subtle">
            <template v-if="data.view === 'buckets'">
              <tr v-for="bucket in data.buckets" :key="bucket.id" class="align-top hover:bg-surface-subtle/60">
                <td class="whitespace-nowrap px-4 py-3"><div class="font-medium text-ink">{{ bucket.bucket_inicio_utc }} → {{ bucket.bucket_fin_utc }}</div><div class="mt-1 text-xs text-ink-muted">Observaciones {{ bucket.primera_observada_en }} → {{ bucket.ultima_observada_en }}</div><div class="mt-1 text-[11px] text-ink-muted">Guardado {{ bucket.created_at }} · #{{ bucket.id }}</div></td>
                <td class="px-4 py-3"><div class="font-semibold text-ink">{{ bucket.unidad_externa }}</div><div class="mt-1 text-xs text-ink-muted">{{ bucket.equipo_codigo || 'Equipo sin código' }}<span v-if="bucket.equipo_patente"> · {{ bucket.equipo_patente }}</span> · ID {{ bucket.equipo_id }}</div></td>
                <td class="whitespace-nowrap px-4 py-3 text-xs text-ink-muted"><div>{{ formatNumber(bucket.combustible_inicio_litros, 2) }} → {{ formatNumber(bucket.combustible_fin_litros, 2) }} l</div></td>
                <td class="whitespace-nowrap px-4 py-3 text-xs text-ink-muted"><div>Odómetro {{ formatNumber(bucket.kilometraje_inicio, 0) }} → {{ formatNumber(bucket.kilometraje_fin, 0) }} km</div><div class="mt-1">Motor {{ formatNumber(bucket.horas_decimales_inicio, 2) }} → {{ formatNumber(bucket.horas_decimales_fin, 2) }} h</div></td>
                <td class="whitespace-nowrap px-4 py-3 text-xs text-ink-muted"><div>Ralentí {{ formatNumber(bucket.segundos_ralenti, 0) }} s</div><div class="mt-1">Motor {{ formatBoolean(bucket.motor_inicio) }} → {{ formatBoolean(bucket.motor_fin) }}</div><div class="mt-1">Ralentí activo {{ formatBoolean(bucket.ralenti_inicio) }} → {{ formatBoolean(bucket.ralenti_fin) }}</div></td>
                <td class="whitespace-nowrap px-4 py-3 text-xs text-ink-muted"><div>Velocidad {{ formatNumber(bucket.velocidad_inicio_kmh) }} → {{ formatNumber(bucket.velocidad_fin_kmh) }} km/h</div><div class="mt-1">Inicio {{ bucket.latitud_inicio ?? '—' }}, {{ bucket.longitud_inicio ?? '—' }}</div><div class="mt-1">Fin {{ bucket.latitud_fin ?? '—' }}, {{ bucket.longitud_fin ?? '—' }}</div></td>
                <td class="max-w-md px-4 py-3 text-xs text-ink-muted">
                  <details v-if="bucket.mensajes_fuente_json" class="space-y-2">
                    <summary class="cursor-pointer font-semibold text-primary">Ver {{ sourceMessageCount(bucket.mensajes_fuente_json) }} mensajes recibidos</summary>
                    <pre class="mt-2 max-h-96 min-w-96 overflow-auto whitespace-pre-wrap break-words rounded-md bg-surface-subtle p-3 text-[11px]">{{ prettyJson(bucket.mensajes_fuente_json) }}</pre>
                  </details>
                  <span v-else>Este intervalo se guardó antes de habilitar el detalle crudo.</span>
                </td>
              </tr>
            </template>
            <template v-else>
            <tr v-for="sample in data.samples" :key="sample.id" class="align-top hover:bg-surface-subtle/60">
              <td class="whitespace-nowrap px-4 py-3"><div class="font-medium text-ink">{{ sample.observada_en }}</div><div class="mt-1 text-xs text-ink-muted">Ingresada {{ sample.registrada_en || '—' }}</div><div class="mt-1 text-[11px] text-ink-muted">#{{ sample.id }}</div></td>
              <td class="px-4 py-3"><div class="font-semibold text-ink">{{ sample.unidad_externa }}</div><div class="mt-1 text-xs text-ink-muted">{{ sample.equipo_codigo || 'Equipo sin código' }}<span v-if="sample.equipo_patente"> · {{ sample.equipo_patente }}</span> · ID {{ sample.equipo_id }}</div></td>
              <td class="whitespace-nowrap px-4 py-3 text-xs text-ink-muted"><div>Lat/Lon: {{ sample.latitud ?? '—' }}, {{ sample.longitud ?? '—' }}</div><div class="mt-1">Velocidad: {{ formatNumber(sample.velocidad_kmh) }} km/h</div><div class="mt-1">Rumbo {{ sample.rumbo ?? '—' }}° · altitud {{ formatNumber(sample.altitud_m) }} m</div><div class="mt-1">Satélites: {{ sample.satelites ?? '—' }}</div></td>
              <td class="whitespace-nowrap px-4 py-3 text-xs text-ink-muted"><div>Odómetro: {{ formatNumber(sample.kilometraje, 0) }} km</div><div class="mt-1">Motor: {{ formatNumber(sample.horas_decimales, 2) }} h</div><div class="mt-1">Combustible: {{ formatNumber(sample.combustible_litros, 2) }} l</div><div class="mt-1">Voltaje: {{ formatNumber(sample.voltaje, 2) }} V</div></td>
              <td class="whitespace-nowrap px-4 py-3 text-xs text-ink-muted"><div>Motor encendido: {{ formatBoolean(sample.motor_encendido) }}</div><div class="mt-1">Ralentí: {{ formatBoolean(sample.ralenti_activo) }}</div></td>
              <td class="max-w-sm px-4 py-3 text-xs text-ink-muted">
                <details v-if="sample.sensores_json || sample.anomalias" class="space-y-2">
                  <summary class="cursor-pointer font-semibold text-primary">Ver datos adicionales</summary>
                  <div v-if="sample.sensores_json"><strong>Sensores recibidos</strong><pre class="mt-1 max-h-56 overflow-auto rounded-md bg-surface-subtle p-2 text-[11px]">{{ prettyJson(sample.sensores_json) }}</pre></div>
                  <div v-if="sample.anomalias" class="mt-2"><strong class="text-warning-strong">Anomalías</strong><pre class="mt-1 max-h-56 overflow-auto rounded-md bg-warning-subtle p-2 text-[11px]">{{ prettyJson(sample.anomalias) }}</pre></div>
                </details>
                <span v-else>Sin sensores adicionales ni anomalías</span>
              </td>
            </tr>
            </template>
          </tbody>
        </table>
      </div>
      <div v-else class="p-8 text-center">
        <CircleStackIcon class="mx-auto size-8 text-ink-muted" aria-hidden="true" />
        <h3 class="mt-3 font-semibold text-ink">No hay capturas guardadas</h3>
        <p class="mx-auto mt-1 max-w-2xl text-sm text-ink-muted">{{ data.view === 'buckets' ? 'No hay intervalos históricos guardados para esta selección. Revisá la integración, los fallos y el cursor de ingesta.' : 'La integración puede responder correctamente, pero las capturas puntuales solo se guardan cuando el proveedor trae una observación nueva para una unidad vinculada.' }}</p>
      </div>
      <nav v-if="data.totalPages > 1" class="flex items-center justify-between border-t border-border-subtle px-5 py-4" aria-label="Paginación de muestras">
        <a v-if="data.page > 1" :href="pageUrl(data.page - 1)" class="rounded-lg border border-border px-3 py-2 text-sm font-semibold text-ink hover:bg-surface-muted">Anterior</a><span v-else class="text-sm text-ink-muted">Primera página</span>
        <span class="text-sm text-ink-muted">Página {{ data.page }} de {{ data.totalPages }}</span>
        <a v-if="data.page < data.totalPages" :href="pageUrl(data.page + 1)" class="rounded-lg border border-border px-3 py-2 text-sm font-semibold text-ink hover:bg-surface-muted">Siguiente</a><span v-else class="text-sm text-ink-muted">Última página</span>
      </nav>
    </section>
  </main>
</template>
