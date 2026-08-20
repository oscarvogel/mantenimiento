<script setup>
import { computed } from 'vue'
import { ArrowRightIcon, ChevronRightIcon } from '@heroicons/vue/20/solid'
import {
  ArrowPathIcon,
  CheckCircleIcon,
  ClipboardDocumentCheckIcon,
  ClipboardDocumentListIcon,
  ClockIcon,
  ExclamationTriangleIcon,
  TruckIcon,
  WrenchScrewdriverIcon,
} from '@heroicons/vue/24/outline'
import ApplicationShell from './components/ApplicationShell.vue'
import DashboardLoading from './components/DashboardLoading.vue'
import MaintenanceStatus from './components/MaintenanceStatus.vue'
import MetricCard from './components/MetricCard.vue'
import UpcomingMaintenance from './components/UpcomingMaintenance.vue'

const props = defineProps({
  dashboard: { type: Object, required: true },
  loading: { type: Boolean, default: false },
})

const firstName = computed(() => props.dashboard.user.name.split(/\s+/)[0] || 'Usuario')
const shell = computed(() => ({
  user: props.dashboard.user,
  company: props.dashboard.company,
  navigation: props.dashboard.navigation,
  notifications: props.dashboard.notifications,
  logout: props.dashboard.logout,
}))

const quickActions = computed(() => [
  {
    label: 'Ver vencidos',
    description: 'Mantenimientos fuera de fecha',
    href: props.dashboard.links.maintenanceOverdue,
    icon: ExclamationTriangleIcon,
  },
  {
    label: 'Asignar servicio',
    description: 'Vincular servicios a equipos',
    href: props.dashboard.links.assignPlan,
    icon: ClipboardDocumentCheckIcon,
  },
  {
    label: 'Nueva OT',
    description: 'Crear desde un mantenimiento',
    href: props.dashboard.links.orders,
    icon: ClipboardDocumentListIcon,
  },
  {
    label: 'Registrar lectura',
    description: 'Actualizar km u horas',
    href: props.dashboard.links.quickReadings,
    icon: ArrowPathIcon,
  },
].filter((action) => action.href && action.href !== '#'))

const attentionItems = computed(() => props.dashboard.upcomingMaintenance
  .filter((item) => ['VENCIDO', 'PROXIMO', 'SIN_DATOS'].includes(item.status))
  .slice(0, 4))

const plansConfigured = computed(() => props.dashboard.metrics.plansConfigured ?? 0)
const equipmentWithoutPlans = computed(() => props.dashboard.metrics.equipmentWithoutPlans ?? 0)
const maintenanceMissingData = computed(() => props.dashboard.metrics.maintenanceMissingData ?? 0)
const controlReady = computed(() => (
  (props.dashboard.metrics.equipmentTotal ?? 0) > 0
  && plansConfigured.value > 0
  && equipmentWithoutPlans.value === 0
  && maintenanceMissingData.value === 0
))

const systemStatus = computed(() => [
  {
    label: 'Equipos cargados',
    detail: `${props.dashboard.metrics.equipmentTotal ?? 0} equipos cargados`,
    done: (props.dashboard.metrics.equipmentTotal ?? 0) > 0,
    href: props.dashboard.links.equipment,
  },
  {
    label: 'Servicios asignados',
    detail: plansConfigured.value === 0
      ? 'Todavía no hay servicios asignados'
      : equipmentWithoutPlans.value > 0
        ? `${equipmentWithoutPlans.value} equipo${equipmentWithoutPlans.value === 1 ? '' : 's'} pendiente${equipmentWithoutPlans.value === 1 ? '' : 's'}`
        : 'Todos los equipos activos tienen servicio',
    done: plansConfigured.value > 0 && equipmentWithoutPlans.value === 0,
    href: props.dashboard.links.assignPlan,
  },
  {
    label: 'Datos base completos',
    detail: maintenanceMissingData.value > 0
      ? `${maintenanceMissingData.value} asignación${maintenanceMissingData.value === 1 ? '' : 'es'} necesita${maintenanceMissingData.value === 1 ? '' : 'n'} datos`
      : plansConfigured.value > 0
        ? 'Información suficiente para calcular vencimientos'
        : 'Se completa al asignar servicios',
    done: plansConfigured.value > 0 && maintenanceMissingData.value === 0,
    href: maintenanceMissingData.value > 0
      ? props.dashboard.links.maintenanceMissingData
      : props.dashboard.links.maintenance,
  },
  {
    label: 'Control preventivo operativo',
    detail: controlReady.value ? 'El sistema puede controlar vencimientos' : 'Completá los puntos pendientes',
    done: controlReady.value,
    href: props.dashboard.links.maintenance,
  },
])

const attentionSummaryUrl = computed(() => {
  if ((props.dashboard.metrics.maintenanceOverdue ?? 0) > 0) return props.dashboard.links.maintenanceOverdue
  if ((props.dashboard.metrics.maintenanceDueSoon ?? 0) > 0) return props.dashboard.links.maintenanceDueSoon
  if (maintenanceMissingData.value > 0) return props.dashboard.links.maintenanceMissingData
  return props.dashboard.links.maintenance
})
</script>

<template>
  <ApplicationShell :shell="shell">
    <DashboardLoading v-if="loading" />
    <template v-else>
      <div
        v-if="!dashboard.available"
        role="status"
        class="mb-6 rounded-lg border border-warning/30 bg-warning-subtle px-4 py-3 text-sm font-medium text-warning-foreground"
      >
        El panel todavía no recibió información del servidor. Podés navegar por las secciones disponibles.
      </div>

      <!-- Bloque A: encabezado operativo -->
      <header class="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
        <div>
          <p class="text-sm font-semibold text-primary">Centro de operaciones</p>
          <h1 class="mt-1 text-balance text-3xl font-bold tracking-tight text-ink sm:text-4xl">
            Buen día, {{ firstName }}
          </h1>
          <p class="mt-2 text-sm leading-6 text-ink-muted sm:text-base">
            {{ dashboard.mode === 'global' ? 'Supervisá la actividad general del sistema.' : 'Esto es lo que necesita atención hoy.' }}
          </p>
        </div>
        <div v-if="dashboard.mode !== 'global'" class="flex flex-col gap-2 sm:flex-row">
          <a
            v-if="dashboard.links.equipment !== '#'"
            :href="dashboard.links.equipment"
            class="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg bg-primary px-4 py-2.5 text-sm font-semibold text-primary-foreground shadow-sm transition hover:bg-primary-hover"
          >
            <TruckIcon class="size-5" aria-hidden="true" />
            Ver equipos
          </a>
          <a
            v-if="dashboard.links.quickReadings !== '#'"
            :href="dashboard.links.quickReadings"
            class="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg border border-primary bg-surface px-4 py-2.5 text-sm font-semibold text-primary transition hover:bg-primary-subtle"
          >
            <ArrowPathIcon class="size-5" aria-hidden="true" />
            Registrar lectura
          </a>
        </div>
      </header>

      <template v-if="dashboard.mode !== 'global'">
        <!-- Bloque B: KPIs -->
        <section aria-label="Indicadores principales" class="mt-7 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
          <MetricCard label="Equipos" :value="dashboard.metrics.equipmentTotal" tone="primary" :href="dashboard.links.equipment" link-label="Ver flota" />
          <MetricCard label="Próximos" :value="dashboard.metrics.maintenanceDueSoon" tone="due" :href="dashboard.links.maintenanceDueSoon" link-label="Revisar próximos" />
          <MetricCard label="Vencidos" :value="dashboard.metrics.maintenanceOverdue" tone="overdue" :href="dashboard.links.maintenanceOverdue" link-label="Atender vencidos" />
          <MetricCard label="OT abiertas" :value="dashboard.metrics.openOrders" tone="orders" :href="dashboard.links.orders" link-label="Ver órdenes" />
        </section>

        <!-- Bloque C: contenido operativo 70/30 -->
        <div class="mt-6 grid items-start gap-6 xl:grid-cols-[minmax(0,2.1fr)_minmax(18rem,0.9fr)]">
          <main class="min-w-0 space-y-6">
            <!-- C1: requieren atención -->
            <section aria-labelledby="attention-title" class="overflow-hidden rounded-xl border border-border bg-surface-raised">
              <div class="flex items-center justify-between gap-4 border-b border-border-subtle px-5 py-4 sm:px-6">
                <div>
                  <h2 id="attention-title" class="text-base font-bold text-ink sm:text-lg">Requieren atención hoy</h2>
                  <p class="mt-0.5 text-sm text-ink-muted">Primero lo urgente; después lo próximo.</p>
                </div>
                <a
                  v-if="attentionItems.length && attentionSummaryUrl !== '#'"
                  :href="attentionSummaryUrl"
                  class="hidden items-center gap-1 text-sm font-semibold text-primary hover:text-primary-hover sm:inline-flex"
                >
                  Ver todas
                  <ArrowRightIcon class="size-4" aria-hidden="true" />
                </a>
              </div>

              <div v-if="attentionItems.length" class="divide-y divide-border-subtle">
                <a
                  v-for="item in attentionItems"
                  :key="item.id"
                  :href="item.actionUrl || item.detailUrl || attentionSummaryUrl"
                  class="group flex items-center gap-4 px-5 py-4 transition hover:bg-brand-50/70 sm:px-6"
                >
                  <span
                    class="flex size-11 shrink-0 items-center justify-center rounded-lg"
                    :class="item.status === 'VENCIDO' ? 'bg-danger-subtle text-danger' : item.status === 'PROXIMO' ? 'bg-warning-subtle text-warning-strong' : 'bg-surface-muted text-ink-muted'"
                  >
                    <ExclamationTriangleIcon v-if="item.status === 'VENCIDO'" class="size-6" aria-hidden="true" />
                    <ClockIcon v-else-if="item.status === 'PROXIMO'" class="size-6" aria-hidden="true" />
                    <WrenchScrewdriverIcon v-else class="size-6" aria-hidden="true" />
                  </span>
                  <span class="min-w-0 flex-1">
                    <span class="block truncate text-sm font-semibold text-ink">{{ item.equipment }} · {{ item.service }}</span>
                    <span class="mt-1 block text-sm" :class="item.status === 'VENCIDO' ? 'font-semibold text-danger' : 'text-ink-muted'">{{ item.remaining }}</span>
                  </span>
                  <MaintenanceStatus :status="item.tone" :label="item.statusLabel" />
                  <ChevronRightIcon class="size-5 shrink-0 text-ink-subtle transition group-hover:translate-x-0.5 group-hover:text-primary" aria-hidden="true" />
                </a>
              </div>

              <div v-else class="flex items-start gap-3 px-5 py-6 sm:px-6">
                <CheckCircleIcon class="mt-0.5 size-6 shrink-0 text-success" aria-hidden="true" />
                <div>
                  <p class="font-semibold text-ink">No hay mantenimientos urgentes</p>
                  <p class="mt-1 text-sm text-ink-muted">La flota no tiene vencidos ni próximos que requieran intervención inmediata.</p>
                </div>
              </div>
            </section>

            <!-- C2: próximos -->
            <UpcomingMaintenance :items="dashboard.upcomingMaintenance" :maintenance-url="dashboard.links.maintenance" />
          </main>

          <aside class="space-y-6">
            <!-- C3: acciones rápidas -->
            <section v-if="quickActions.length" aria-labelledby="quick-actions-title" class="rounded-xl border border-border bg-surface-raised p-5 sm:p-6">
              <h2 id="quick-actions-title" class="text-base font-bold text-ink sm:text-lg">Acciones rápidas</h2>
              <div class="mt-4 space-y-2.5">
                <a
                  v-for="action in quickActions"
                  :key="action.label"
                  :href="action.href"
                  class="group flex items-center gap-3 rounded-lg border border-border-subtle bg-surface px-3.5 py-3 transition hover:border-primary/30 hover:bg-primary-subtle/40"
                >
                  <span class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-primary-subtle text-primary">
                    <component :is="action.icon" class="size-5" aria-hidden="true" />
                  </span>
                  <span class="min-w-0 flex-1">
                    <span class="block text-sm font-semibold text-ink group-hover:text-primary">{{ action.label }}</span>
                    <span class="mt-0.5 block text-xs text-ink-muted">{{ action.description }}</span>
                  </span>
                  <ChevronRightIcon class="size-4 shrink-0 text-ink-subtle group-hover:text-primary" aria-hidden="true" />
                </a>
              </div>
            </section>

            <!-- C4: estado del sistema -->
            <section aria-labelledby="system-status-title" class="rounded-xl border border-border bg-surface-raised p-5 sm:p-6">
              <h2 id="system-status-title" class="text-base font-bold text-ink sm:text-lg">Estado del sistema</h2>
              <div class="mt-4 divide-y divide-border-subtle overflow-hidden rounded-lg border border-border-subtle">
                <a
                  v-for="status in systemStatus"
                  :key="status.label"
                  :href="status.href"
                  class="flex items-start gap-3 bg-surface px-3.5 py-3.5 transition hover:bg-surface-subtle"
                >
                  <span
                    class="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-full"
                    :class="status.done ? 'bg-success-subtle text-success' : 'bg-warning-subtle text-warning-strong'"
                  >
                    <CheckCircleIcon v-if="status.done" class="size-5" aria-hidden="true" />
                    <ExclamationTriangleIcon v-else class="size-5" aria-hidden="true" />
                  </span>
                  <span class="min-w-0 flex-1">
                    <span class="block text-sm font-semibold text-ink">{{ status.label }}</span>
                    <span class="mt-0.5 block text-xs leading-5 text-ink-muted">{{ status.detail }}</span>
                  </span>
                </a>
              </div>
              <a
                v-if="!controlReady && dashboard.links.assignPlan !== '#'"
                :href="dashboard.links.assignPlan"
                class="mt-4 inline-flex items-center gap-1 text-sm font-semibold text-primary hover:text-primary-hover"
              >
                Completar configuración
                <ArrowRightIcon class="size-4" aria-hidden="true" />
              </a>
            </section>
          </aside>
        </div>
      </template>
    </template>
  </ApplicationShell>
</template>