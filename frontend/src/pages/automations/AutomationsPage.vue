<script setup>
import { computed } from 'vue'
import { ArrowPathIcon, BellAlertIcon, ChartBarSquareIcon, ClockIcon, Cog6ToothIcon, HomeIcon, InboxArrowDownIcon, InformationCircleIcon, SignalIcon } from '@heroicons/vue/24/outline'

const props = defineProps({
  data: { type: Object, required: true },
})

const overview = computed(() => props.data?.overview ?? {})
const processes = computed(() => Array.isArray(overview.value.processes) ? overview.value.processes : [])
const deliveries = computed(() => overview.value.deliveries ?? {})
const limitations = computed(() => Array.isArray(overview.value.limitations) ? overview.value.limitations : [])
const portalUrl = computed(() => typeof props.data?.portalUrl === 'string' ? props.data.portalUrl : '')

const dateTime = new Intl.DateTimeFormat('es-AR', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })
const number = new Intl.NumberFormat('es-AR')

const dateLabel = (value) => {
  if (!value) return 'Sin evidencia de ejecución'
  const parsed = new Date(String(value).replace(' ', 'T'))
  return Number.isNaN(parsed.getTime()) ? 'Sin fecha válida' : dateTime.format(parsed)
}
const activityLabel = (value) => {
  if (!value) return 'Sin actividad registrada'
  const parsed = new Date(String(value).replace(' ', 'T'))
  return Number.isNaN(parsed.getTime()) ? 'Sin fecha válida' : dateTime.format(parsed)
}

const enabledLabel = (enabled) => enabled === true ? 'Configurado' : (enabled === false ? 'Desactivado' : 'Depende de la configuración global')
const enabledTone = (enabled) => enabled === true ? 'bg-success-subtle text-success-strong' : (enabled === false ? 'bg-surface-muted text-ink-muted' : 'bg-warning-subtle text-warning-strong')

const outcomeLabel = (outcome) => ({
  SIN_FALLAS_REGISTRADAS: 'Sin fallas registradas',
  CON_PENDIENTES: 'Entregas pendientes',
  CON_FALLOS: 'Requiere atención',
  SIN_EJECUCION: 'Sin ejecución registrada',
}[outcome] ?? 'Sin datos suficientes')

const outcomeTone = (outcome) => ({
  SIN_FALLAS_REGISTRADAS: 'bg-success-subtle text-success-strong',
  CON_PENDIENTES: 'bg-warning-subtle text-warning-strong',
  CON_FALLOS: 'bg-danger-subtle text-danger-strong',
  SIN_EJECUCION: 'bg-surface-muted text-ink-muted',
}[outcome] ?? 'bg-surface-muted text-ink-muted')

const actionMessage = (process) => {
  if (Number(process.failed) > 0) return `Revisar ${number.format(process.failed)} entrega(s) fallida(s).`
  if (Number(process.pending) > 0) return `${number.format(process.pending)} entrega(s) pendiente(s) de resolver.`
  if (!process.lastRunAt) return 'Todavía no hay ejecución registrada para tu alcance.'
  return 'No hay acciones pendientes registradas.'
}

const deliveryRows = computed(() => [
  { key: 'email', label: 'Correo electrónico', icon: InboxArrowDownIcon, data: deliveries.value.email ?? {} },
  { key: 'webpush', label: 'Web Push', icon: BellAlertIcon, data: deliveries.value.webpush ?? {} },
  { key: 'whatsapp', label: 'WhatsApp', icon: SignalIcon, data: deliveries.value.whatsapp ?? {} },
])
</script>

<template>
  <div>
    <header class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
      <div>
        <p class="flex items-center gap-2 text-sm font-semibold text-primary"><Cog6ToothIcon class="size-5" aria-hidden="true" />Procesos y entregas</p>
        <h1 class="mt-1 text-2xl font-bold tracking-tight text-ink sm:text-3xl">Automatizaciones</h1>
        <p class="mt-2 max-w-3xl text-sm leading-6 text-ink-muted">Qué está configurado, qué evidencia existe y qué necesita atención, sin ejecutar ni enviar nada desde esta pantalla.</p>
      </div>
      <a v-if="portalUrl" :href="portalUrl" class="inline-flex min-h-11 items-center justify-center gap-2 self-start rounded-lg border border-border-strong bg-surface-raised px-4 py-2.5 text-sm font-semibold text-ink hover:bg-surface-muted sm:self-auto">
        <HomeIcon class="size-5" aria-hidden="true" />Volver al inicio
      </a>
    </header>

    <div class="mt-6 flex items-start gap-3 rounded-xl border border-info-subtle bg-info-subtle p-4 text-sm leading-6 text-info-strong" role="note">
      <InformationCircleIcon class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
      <p>Sólo lectura. Esta pantalla no envía correos, mensajes de WhatsApp ni notificaciones push, y no reintenta procesos fallidos.</p>
    </div>

    <section aria-labelledby="process-title" class="mt-6">
      <div class="mb-3 flex flex-wrap items-end justify-between gap-2">
        <div>
          <h2 id="process-title" class="text-lg font-bold text-ink">Seguimiento por proceso</h2>
          <p class="mt-1 text-sm text-ink-muted">La programación configurada se muestra separada de la ejecución observada y del estado de las entregas.</p>
        </div>
      </div>

      <div v-if="processes.length" class="grid gap-4 lg:grid-cols-2">
        <article v-for="process in processes" :key="process.key" class="rounded-xl border border-border bg-surface-raised p-5">
          <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
              <h3 class="text-base font-bold text-ink">{{ process.label }}</h3>
              <p class="mt-1 text-sm leading-6 text-ink-muted">{{ process.description }}</p>
            </div>
            <span class="shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold" :class="enabledTone(process.enabled)">{{ enabledLabel(process.enabled) }}</span>
          </div>

          <dl class="mt-4 grid gap-3 sm:grid-cols-3">
            <div class="rounded-lg bg-surface-muted p-3">
              <dt class="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-ink-muted"><ClockIcon class="size-3.5" aria-hidden="true" />Programación</dt>
              <dd class="mt-1 text-sm font-medium text-ink">{{ process.schedule ?? 'No disponible' }}</dd>
            </div>
            <div class="rounded-lg bg-surface-muted p-3">
              <dt class="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-ink-muted"><ArrowPathIcon class="size-3.5" aria-hidden="true" />Última evidencia</dt>
              <dd class="mt-1 text-sm font-medium text-ink">{{ dateLabel(process.lastRunAt) }}</dd>
            </div>
            <div class="rounded-lg bg-surface-muted p-3">
              <dt class="text-xs font-semibold uppercase tracking-wide text-ink-muted">Resultado observado</dt>
              <dd class="mt-1"><span class="rounded-full px-2.5 py-1 text-xs font-semibold" :class="outcomeTone(process.lastOutcome)">{{ outcomeLabel(process.lastOutcome) }}</span></dd>
            </div>
          </dl>

          <div class="mt-4 grid grid-cols-3 gap-2 text-center">
            <div class="rounded-lg border border-border-subtle p-2"><p class="text-xs font-semibold uppercase tracking-wide text-ink-muted">Pendientes</p><p class="mt-1 text-lg font-bold text-warning-strong">{{ number.format(Number(process.pending) || 0) }}</p></div>
            <div class="rounded-lg border border-border-subtle p-2"><p class="text-xs font-semibold uppercase tracking-wide text-ink-muted">Fallidas</p><p class="mt-1 text-lg font-bold text-danger-strong">{{ number.format(Number(process.failed) || 0) }}</p></div>
            <div class="rounded-lg border border-border-subtle p-2"><p class="text-xs font-semibold uppercase tracking-wide text-ink-muted">Enviadas</p><p class="mt-1 text-lg font-bold text-success-strong">{{ number.format(Number(process.sent) || 0) }}</p></div>
          </div>

          <p class="mt-4 rounded-lg px-3 py-2 text-sm font-medium" :class="Number(process.failed) > 0 ? 'bg-danger-subtle text-danger-strong' : (Number(process.pending) > 0 ? 'bg-warning-subtle text-warning-strong' : 'bg-surface-muted text-ink-muted')">{{ actionMessage(process) }}</p>
        </article>
      </div>
      <p v-else role="status" class="rounded-xl border border-border bg-surface-raised px-5 py-10 text-center text-sm text-ink-muted">No hay evidencia de automatizaciones para tu alcance.</p>
    </section>

    <section aria-labelledby="deliveries-title" class="mt-8">
      <h2 id="deliveries-title" class="text-lg font-bold text-ink">Entregas por canal</h2>
      <p class="mt-1 text-sm text-ink-muted">Conteos agregados de las entregas registradas en tu empresa y sucursales autorizadas.</p>
      <div class="mt-3 grid gap-4 sm:grid-cols-3">
        <article v-for="row in deliveryRows" :key="row.key" class="rounded-xl border border-border bg-surface-raised p-5">
          <div class="flex items-center gap-2"><component :is="row.icon" class="size-5 text-primary" aria-hidden="true" /><h3 class="text-base font-bold text-ink">{{ row.label }}</h3></div>
          <dl class="mt-4 space-y-2 text-sm">
            <div class="flex items-center justify-between gap-3"><dt class="text-ink-muted">Pendientes</dt><dd class="font-semibold text-warning-strong">{{ number.format(Number(row.data.pending) || 0) }}</dd></div>
            <div class="flex items-center justify-between gap-3"><dt class="text-ink-muted">Fallidas</dt><dd class="font-semibold text-danger-strong">{{ number.format(Number(row.data.failed) || 0) }}</dd></div>
            <div class="flex items-center justify-between gap-3"><dt class="text-ink-muted">Enviadas</dt><dd class="font-semibold text-success-strong">{{ number.format(Number(row.data.sent) || 0) }}</dd></div>
            <div class="flex items-center justify-between gap-3 border-t border-border-subtle pt-2"><dt class="text-ink-muted">Última actividad</dt><dd class="font-medium text-ink">{{ activityLabel(row.data.lastActivityAt) }}</dd></div>
          </dl>
        </article>
      </div>
    </section>

    <section aria-labelledby="limitations-title" class="mt-8 rounded-xl border border-border bg-surface-raised p-5 sm:p-6">
      <h2 id="limitations-title" class="flex items-center gap-2 text-lg font-bold text-ink"><ChartBarSquareIcon class="size-5 text-primary" aria-hidden="true" />Alcance de esta evidencia</h2>
      <ul class="mt-3 list-disc space-y-1 pl-5 text-sm leading-6 text-ink-muted">
        <li v-for="limitation in limitations" :key="limitation">{{ limitation }}</li>
      </ul>
    </section>
  </div>
</template>
