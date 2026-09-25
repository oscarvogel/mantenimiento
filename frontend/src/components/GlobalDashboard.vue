<script setup>
import { computed } from 'vue'
import {
  BellAlertIcon,
  BuildingOffice2Icon,
  CalendarDaysIcon,
  ChatBubbleLeftRightIcon,
  CheckCircleIcon,
  ClockIcon,
  DocumentCheckIcon,
  EnvelopeIcon,
  ExclamationTriangleIcon,
  PaperAirplaneIcon,
  TruckIcon,
  UserGroupIcon,
} from '@heroicons/vue/24/outline'
import { ArrowRightIcon, ChevronRightIcon } from '@heroicons/vue/20/solid'

const props = defineProps({
  data: {
    type: Object,
    required: true,
  },
})

const formatter = new Intl.NumberFormat('es-AR')
const number = (value) => formatter.format(Number(value) || 0)

const progress = (value, total) => {
  const current = Number(value) || 0
  const maximum = Number(total) || 0
  if (maximum <= 0 || current <= 0) return 0
  return Math.max(5, Math.min(100, Math.round((current / maximum) * 100)))
}

const kpis = computed(() => {
  const m = props.data.metrics
  return [
    {
      key: 'companies',
      label: 'Empresas activas',
      value: number(m.companiesActive),
      detail: `de ${number(m.companiesTotal)} registradas`,
      icon: BuildingOffice2Icon,
      tone: 'blue',
      progress: progress(m.companiesActive, m.companiesTotal),
    },
    {
      key: 'equipment',
      label: 'Equipos activos',
      value: number(m.equipmentActive),
      detail: `de ${number(m.equipmentTotal)} equipos`,
      icon: TruckIcon,
      tone: 'green',
      progress: progress(m.equipmentActive, m.equipmentTotal),
    },
    {
      key: 'overdue',
      label: 'Vencimientos vencidos',
      value: number(m.expirationOverdue),
      detail: m.expirationOverdue === 1 ? 'requiere atención' : 'requieren atención',
      icon: ExclamationTriangleIcon,
      tone: 'red',
      progress: progress(m.expirationOverdue, Math.max(m.equipmentActive, 1)),
    },
    {
      key: 'upcoming',
      label: 'Próximos 30 días',
      value: number(m.expirationUpcoming30),
      detail: 'vencimientos programados',
      icon: CalendarDaysIcon,
      tone: 'amber',
      progress: progress(m.expirationUpcoming30, Math.max(m.equipmentActive, 1)),
    },
    {
      key: 'readings',
      label: 'KM pendientes',
      value: number(m.pendingReadings),
      detail: 'equipos sin lectura reciente',
      icon: ClockIcon,
      tone: 'violet',
      progress: progress(m.pendingReadings, Math.max(m.equipmentActive, 1)),
    },
    {
      key: 'notifications',
      label: 'Alertas de notificación',
      value: number(m.notificationAlerts),
      detail: 'fallidas o en reintento',
      icon: BellAlertIcon,
      tone: 'gold',
      progress: progress(m.notificationAlerts, Math.max(m.companiesActive, 1)),
    },
  ]
})

const activityRows = computed(() => [
  { label: 'KM cargados hoy', value: props.data.activity.readingsToday, suffix: ' lecturas', icon: TruckIcon, tone: 'blue' },
  { label: 'Renovaciones registradas', value: props.data.activity.renewalsToday, icon: DocumentCheckIcon, tone: 'blue' },
  { label: 'Evidencias cargadas', value: props.data.activity.evidenceToday, icon: DocumentCheckIcon, tone: 'blue' },
  { label: 'WhatsApp enviados', value: props.data.activity.whatsappSentToday, icon: PaperAirplaneIcon, tone: 'green' },
  { label: 'Emails enviados', value: props.data.activity.emailSentToday, icon: EnvelopeIcon, tone: 'red' },
  { label: 'Consultas del chatbot', value: props.data.activity.chatQueriesToday, icon: ChatBubbleLeftRightIcon, tone: 'blue' },
])

const communicationRows = computed(() => [
  { label: 'WhatsApp', ...props.data.communications.whatsapp, icon: ChatBubbleLeftRightIcon },
  { label: 'Email', ...props.data.communications.email, icon: EnvelopeIcon },
  { label: 'Cron de tareas', ...props.data.communications.cron, icon: ClockIcon },
])

const chatbotRate = computed(() => {
  const queries = props.data.chatbot.queriesToday
  const responses = props.data.chatbot.responsesToday
  if (!queries) return 0
  return Math.min(100, Math.round((responses / queries) * 100))
})

const toneClass = (tone) => ({
  blue: {
    icon: 'bg-primary-subtle text-primary',
    bar: 'bg-primary',
  },
  green: {
    icon: 'bg-success-subtle text-success',
    bar: 'bg-success',
  },
  red: {
    icon: 'bg-danger-subtle text-danger',
    bar: 'bg-danger',
  },
  amber: {
    icon: 'bg-warning-subtle text-warning',
    bar: 'bg-warning',
  },
  violet: {
    icon: 'bg-violet-500/15 text-violet-300',
    bar: 'bg-violet-400',
  },
  gold: {
    icon: 'bg-accent-subtle text-accent',
    bar: 'bg-accent',
  },
}[tone] ?? {
  icon: 'bg-primary-subtle text-primary',
  bar: 'bg-primary',
})

const companyTone = (tone) => ({
  success: { dot: 'bg-success', bar: 'bg-success' },
  warning: { dot: 'bg-warning', bar: 'bg-warning' },
  danger: { dot: 'bg-danger', bar: 'bg-danger' },
}[tone] ?? { dot: 'bg-success', bar: 'bg-success' })

const communicationTone = (tone) => ({
  success: 'border-success/20 bg-success-subtle text-success-strong',
  warning: 'border-warning/20 bg-warning-subtle text-warning-foreground',
  danger: 'border-danger/20 bg-danger-subtle text-danger-strong',
  muted: 'border-border bg-surface-muted text-ink-muted',
}[tone] ?? 'border-border bg-surface-muted text-ink-muted')

const attentionTone = (tone) => tone === 'danger'
  ? 'bg-danger-subtle text-danger'
  : 'bg-warning-subtle text-warning'

const companyPressure = (company) => Math.min(
  100,
  Math.max(
    company.tone === 'success' ? 12 : 18,
    progress(
      company.overdue + company.upcoming + company.pendingReadings + company.notificationAlerts,
      Math.max(company.equipment, 1),
    ),
  ),
)
</script>

<template>
  <div class="mt-7 space-y-5">
    <section aria-label="Indicadores globales" class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
      <article
        v-for="(kpi, index) in kpis"
        :key="kpi.key"
        v-reveal="{ delay: 25 + index * 20 }"
        class="group relative overflow-hidden rounded-xl border border-border bg-surface-raised p-4 shadow-card transition duration-200 hover:-translate-y-0.5 hover:border-border-strong"
      >
        <div class="flex min-w-0 items-start gap-3">
          <span
            class="flex size-11 shrink-0 items-center justify-center rounded-xl ring-1 ring-white/5"
            :class="toneClass(kpi.tone).icon"
          >
            <component :is="kpi.icon" class="size-6" aria-hidden="true" />
          </span>
          <div class="min-w-0">
            <p class="truncate text-xs font-semibold text-ink-muted">{{ kpi.label }}</p>
            <p class="mt-1 text-2xl font-bold tracking-tight text-ink-strong">{{ kpi.value }}</p>
          </div>
        </div>
        <p class="mt-2 truncate text-[11px] text-ink-subtle">{{ kpi.detail }}</p>
        <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-surface-muted">
          <span
            class="block h-full rounded-full transition-all"
            :class="toneClass(kpi.tone).bar"
            :style="{ width: `${kpi.progress}%` }"
          ></span>
        </div>
      </article>
    </section>

    <div class="grid items-stretch gap-5 xl:grid-cols-[minmax(0,1.55fr)_minmax(20rem,0.78fr)]">
      <section v-reveal="{ delay: 90 }" class="overflow-hidden rounded-xl border border-border bg-surface-raised shadow-card" aria-labelledby="global-attention-title">
        <div class="flex items-center justify-between gap-4 border-b border-border-subtle px-5 py-4">
          <div class="flex min-w-0 items-center gap-3">
            <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-danger-subtle text-danger">
              <ExclamationTriangleIcon class="size-5" aria-hidden="true" />
            </span>
            <div class="min-w-0">
              <h2 id="global-attention-title" class="text-base font-bold text-ink sm:text-lg">Requieren atención</h2>
              <p class="mt-0.5 truncate text-xs text-ink-muted sm:text-sm">Empresas con situaciones que necesitan revisión.</p>
            </div>
          </div>
          <a
            v-if="data.links.companies !== '#'"
            :href="data.links.companies"
            class="hidden shrink-0 items-center gap-1 text-sm font-semibold text-primary hover:text-primary-hover sm:inline-flex"
          >
            Ver todas
            <ArrowRightIcon class="size-4" aria-hidden="true" />
          </a>
        </div>

        <div v-if="data.attention.length" class="overflow-x-auto">
          <table class="w-full min-w-[42rem] border-collapse text-left">
            <thead>
              <tr class="border-b border-border-subtle bg-surface/35 text-[11px] font-semibold uppercase tracking-wide text-ink-subtle">
                <th class="px-5 py-3">Empresa</th>
                <th class="px-4 py-3">Tipo de situación</th>
                <th class="px-4 py-3 text-center">Cantidad</th>
                <th class="px-5 py-3 text-right">Acción</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-border-subtle">
              <tr v-for="item in data.attention" :key="item.id" class="transition hover:bg-surface-muted/45">
                <td class="px-5 py-3.5">
                  <div class="flex items-center gap-2.5">
                    <span class="size-2.5 shrink-0 rounded-full" :class="item.tone === 'danger' ? 'bg-danger' : 'bg-warning'"></span>
                    <span class="max-w-48 truncate text-sm font-semibold text-ink">{{ item.company }}</span>
                  </div>
                </td>
                <td class="px-4 py-3.5">
                  <div class="flex items-center gap-2.5">
                    <span class="flex size-7 shrink-0 items-center justify-center rounded-lg" :class="attentionTone(item.tone)">
                      <ExclamationTriangleIcon v-if="item.tone === 'danger'" class="size-4" aria-hidden="true" />
                      <ClockIcon v-else class="size-4" aria-hidden="true" />
                    </span>
                    <span class="text-sm text-ink-muted">{{ item.label }}</span>
                  </div>
                </td>
                <td class="px-4 py-3.5 text-center text-sm font-bold text-ink">{{ number(item.count) }}</td>
                <td class="px-5 py-3.5 text-right">
                  <a
                    v-if="item.actionUrl !== '#'"
                    :href="item.actionUrl"
                    class="inline-flex min-h-8 items-center justify-center rounded-md border border-primary/30 bg-primary-subtle px-3 text-xs font-semibold text-primary transition hover:border-primary/50 hover:bg-primary-subtle/80"
                  >
                    {{ item.actionLabel }}
                  </a>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-else class="flex min-h-64 items-center justify-center px-6 py-10 text-center">
          <div>
            <span class="mx-auto flex size-12 items-center justify-center rounded-full bg-success-subtle text-success">
              <CheckCircleIcon class="size-7" aria-hidden="true" />
            </span>
            <p class="mt-3 font-semibold text-ink">No hay situaciones críticas pendientes</p>
            <p class="mt-1 text-sm text-ink-muted">El tablero no encontró alertas globales para priorizar.</p>
          </div>
        </div>
      </section>

      <section v-reveal="{ delay: 120 }" class="overflow-hidden rounded-xl border border-border bg-surface-raised shadow-card" aria-labelledby="today-title">
        <div class="flex items-center gap-3 border-b border-border-subtle px-5 py-4">
          <span class="flex size-9 items-center justify-center rounded-lg bg-primary-subtle text-primary">
            <ClockIcon class="size-5" aria-hidden="true" />
          </span>
          <div>
            <h2 id="today-title" class="text-base font-bold text-ink sm:text-lg">Actividad de hoy</h2>
            <p class="mt-0.5 text-xs text-ink-muted">Movimiento registrado por el sistema.</p>
          </div>
        </div>
        <div class="divide-y divide-border-subtle px-5">
          <div v-for="row in activityRows" :key="row.label" class="flex items-center gap-3 py-3">
            <span class="flex size-8 shrink-0 items-center justify-center rounded-lg" :class="toneClass(row.tone).icon">
              <component :is="row.icon" class="size-[18px]" aria-hidden="true" />
            </span>
            <span class="min-w-0 flex-1 truncate text-sm text-ink-muted">{{ row.label }}</span>
            <span class="shrink-0 text-right text-sm font-bold text-ink-strong">
              {{ number(row.value) }}<span v-if="row.suffix" class="ml-1 text-[10px] font-medium text-ink-subtle">{{ row.suffix }}</span>
            </span>
          </div>
        </div>
      </section>
    </div>

    <div class="grid items-stretch gap-5 xl:grid-cols-[minmax(0,1.45fr)_minmax(17rem,0.82fr)_minmax(18rem,0.92fr)]">
      <section v-reveal="{ delay: 140 }" class="overflow-hidden rounded-xl border border-border bg-surface-raised shadow-card" aria-labelledby="companies-state-title">
        <div class="flex items-center justify-between gap-4 border-b border-border-subtle px-5 py-4">
          <div class="flex items-center gap-3">
            <span class="flex size-9 items-center justify-center rounded-lg bg-primary-subtle text-primary">
              <BuildingOffice2Icon class="size-5" aria-hidden="true" />
            </span>
            <div>
              <h2 id="companies-state-title" class="font-bold text-ink">Estado por empresa</h2>
              <p class="mt-0.5 text-xs text-ink-muted">Estado general de las principales empresas.</p>
            </div>
          </div>
          <a
            v-if="data.links.companies !== '#'"
            :href="data.links.companies"
            class="hidden items-center gap-1 text-xs font-semibold text-primary hover:text-primary-hover sm:inline-flex"
          >
            Ver todas
            <ArrowRightIcon class="size-3.5" aria-hidden="true" />
          </a>
        </div>

        <div v-if="data.companies.length" class="grid gap-3 p-4 sm:grid-cols-2 2xl:grid-cols-4">
          <a
            v-for="company in data.companies.slice(0, 4)"
            :key="company.id"
            :href="company.actionUrl"
            class="group rounded-xl border border-border-subtle bg-surface/45 p-3.5 transition hover:border-primary/30 hover:bg-primary-subtle/20"
          >
            <div class="flex items-start gap-2">
              <span class="mt-1 size-2.5 shrink-0 rounded-full" :class="companyTone(company.tone).dot"></span>
              <p class="min-w-0 flex-1 text-sm font-semibold leading-5 text-ink">{{ company.name }}</p>
              <ChevronRightIcon class="mt-0.5 size-4 shrink-0 text-ink-subtle transition group-hover:translate-x-0.5 group-hover:text-primary" aria-hidden="true" />
            </div>
            <p class="mt-3 text-xs text-ink-muted">{{ number(company.equipment) }} equipos</p>
            <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-surface-muted">
              <span
                class="block h-full rounded-full"
                :class="companyTone(company.tone).bar"
                :style="{ width: `${companyPressure(company)}%` }"
              ></span>
            </div>
            <div class="mt-3 flex items-center justify-between gap-2 text-[11px]">
              <span class="inline-flex items-center gap-1 text-danger" title="Vencidos">
                <ExclamationTriangleIcon class="size-3.5" aria-hidden="true" />{{ number(company.overdue) }}
              </span>
              <span class="inline-flex items-center gap-1 text-warning" title="Próximos">
                <CalendarDaysIcon class="size-3.5" aria-hidden="true" />{{ number(company.upcoming) }}
              </span>
              <span class="inline-flex items-center gap-1 text-violet-300" title="KM pendientes">
                <ClockIcon class="size-3.5" aria-hidden="true" />{{ number(company.pendingReadings) }}
              </span>
              <span class="inline-flex items-center gap-1 text-ink-subtle" title="Alertas de notificación">
                <BellAlertIcon class="size-3.5" aria-hidden="true" />{{ number(company.notificationAlerts) }}
              </span>
            </div>
          </a>
        </div>
        <div v-else class="px-5 py-8 text-sm text-ink-muted">No hay empresas activas para mostrar.</div>
      </section>

      <section v-reveal="{ delay: 170 }" class="overflow-hidden rounded-xl border border-border bg-surface-raised shadow-card" aria-labelledby="communications-title">
        <div class="flex items-center gap-3 border-b border-border-subtle px-5 py-4">
          <span class="flex size-9 items-center justify-center rounded-lg bg-primary-subtle text-primary">
            <PaperAirplaneIcon class="size-5" aria-hidden="true" />
          </span>
          <div>
            <h2 id="communications-title" class="font-bold text-ink">Comunicaciones</h2>
            <p class="mt-0.5 text-xs text-ink-muted">Estado de los servicios de comunicación.</p>
          </div>
        </div>
        <div class="divide-y divide-border-subtle px-5">
          <div v-for="row in communicationRows" :key="row.label" class="py-3.5">
            <div class="flex items-center gap-3">
              <component :is="row.icon" class="size-5 shrink-0 text-primary" aria-hidden="true" />
              <span class="min-w-0 flex-1 truncate text-sm font-medium text-ink">{{ row.label }}</span>
              <span class="inline-flex shrink-0 rounded-full border px-2.5 py-1 text-[10px] font-bold" :class="communicationTone(row.tone)">
                {{ row.status }}
              </span>
            </div>
            <p v-if="row.lastRun" class="mt-1.5 pl-8 text-[10px] text-ink-subtle">Última ejecución: {{ row.lastRun }}</p>
          </div>
        </div>
        <div class="border-t border-border-subtle px-5 py-3">
          <a v-if="data.links.notifications !== '#'" :href="data.links.notifications" class="inline-flex items-center gap-1 text-xs font-semibold text-primary hover:text-primary-hover">
            Configurar canales
            <ArrowRightIcon class="size-3.5" aria-hidden="true" />
          </a>
        </div>
      </section>

      <div class="grid gap-5">
        <section v-reveal="{ delay: 190 }" class="overflow-hidden rounded-xl border border-border bg-surface-raised shadow-card" aria-labelledby="drivers-title">
          <div class="flex items-center gap-3 border-b border-border-subtle px-5 py-3.5">
            <span class="flex size-8 items-center justify-center rounded-lg bg-primary-subtle text-primary">
              <UserGroupIcon class="size-[18px]" aria-hidden="true" />
            </span>
            <div>
              <h2 id="drivers-title" class="text-sm font-bold text-ink">Choferes y KM</h2>
              <p class="text-[11px] text-ink-muted">Información global de la flota.</p>
            </div>
          </div>
          <div class="divide-y divide-border-subtle px-5">
            <div class="flex items-center justify-between gap-3 py-2.5 text-sm">
              <span class="text-ink-muted">Choferes activos</span>
              <strong class="text-ink">{{ number(data.drivers.active) }}</strong>
            </div>
            <div class="flex items-center justify-between gap-3 py-2.5 text-sm">
              <span class="text-ink-muted">KM pendientes</span>
              <strong class="text-danger">{{ number(data.drivers.pendingReadings) }}</strong>
            </div>
            <div class="flex items-center justify-between gap-3 py-2.5 text-sm">
              <span class="text-ink-muted">Lecturas de hoy</span>
              <strong class="text-success">{{ number(data.drivers.readingsToday) }}</strong>
            </div>
          </div>
        </section>

        <section v-reveal="{ delay: 210 }" class="overflow-hidden rounded-xl border border-border bg-surface-raised shadow-card" aria-labelledby="chatbot-title">
          <div class="flex items-start justify-between gap-3 border-b border-border-subtle px-5 py-3.5">
            <div class="flex items-center gap-3">
              <span class="flex size-8 items-center justify-center rounded-lg bg-primary-subtle text-primary">
                <ChatBubbleLeftRightIcon class="size-[18px]" aria-hidden="true" />
              </span>
              <div>
                <h2 id="chatbot-title" class="text-sm font-bold text-ink">Chatbot</h2>
                <p class="text-[11px] text-ink-muted">Uso del asistente virtual.</p>
              </div>
            </div>
            <a v-if="data.links.chatAudit !== '#'" :href="data.links.chatAudit" class="text-[10px] font-semibold text-primary hover:text-primary-hover">Ver auditoría</a>
          </div>
          <div class="flex items-center gap-5 px-5 py-4">
            <div class="min-w-0 flex-1">
              <p class="text-2xl font-bold text-ink">{{ number(data.chatbot.queriesToday) }}</p>
              <p class="text-[11px] text-ink-muted">consultas hoy</p>
            </div>
            <div class="flex items-center gap-3 border-l border-border-subtle pl-5">
              <div
                class="relative flex size-11 items-center justify-center rounded-full"
                :style="{ background: `conic-gradient(rgb(var(--success)) ${chatbotRate}%, rgb(var(--surface-muted)) 0)` }"
              >
                <span class="absolute inset-[4px] rounded-full bg-surface-raised"></span>
                <span class="relative text-[10px] font-bold text-ink">{{ chatbotRate }}%</span>
              </div>
              <div>
                <p class="text-xs font-semibold text-ink">{{ number(data.chatbot.responsesToday) }}</p>
                <p class="text-[10px] text-ink-muted">respuestas IA</p>
              </div>
            </div>
          </div>
        </section>
      </div>
    </div>
  </div>
</template>
