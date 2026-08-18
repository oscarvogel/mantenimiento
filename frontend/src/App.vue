<script setup>
import { computed } from 'vue'
import {
  ArrowPathIcon,
  ClipboardDocumentCheckIcon,
  ExclamationTriangleIcon,
  PlusCircleIcon,
  TruckIcon,
  WrenchScrewdriverIcon,
} from '@heroicons/vue/24/outline'
import ApplicationShell from './components/ApplicationShell.vue'
import DashboardLoading from './components/DashboardLoading.vue'
import MetricCard from './components/MetricCard.vue'
import UpcomingMaintenance from './components/UpcomingMaintenance.vue'

const props = defineProps({
  dashboard: {
    type: Object,
    required: true,
  },
  loading: {
    type: Boolean,
    default: false,
  },
})

const firstName = computed(() => props.dashboard.user.name.split(/\s+/)[0] || 'Usuario')
const shell = computed(() => ({
  user: props.dashboard.user,
  company: props.dashboard.company,
  navigation: props.dashboard.navigation,
  notifications: props.dashboard.notifications,
  logout: props.dashboard.logout,
}))

const operationalActions = computed(() => [
  {
    label: 'Agregar equipo',
    description: 'Alta de un nuevo camión, máquina o vehículo.',
    href: props.dashboard.links.equipmentCreate,
    icon: PlusCircleIcon,
  },
  {
    label: 'Asignar plan',
    description: 'Elegí un equipo y vinculale su mantenimiento preventivo.',
    href: props.dashboard.links.assignPlan,
    icon: ClipboardDocumentCheckIcon,
  },
  {
    label: 'Registrar mantenimiento',
    description: 'Cargá el último servicio realizado y actualizá su base.',
    href: props.dashboard.links.registerMaintenance,
    icon: WrenchScrewdriverIcon,
  },
  {
    label: 'Actualizar km / horas',
    description: 'Carga rápida de lecturas para mantener vencimientos al día.',
    href: props.dashboard.links.quickReadings,
    icon: ArrowPathIcon,
  },
  {
    label: 'Ver vencidos',
    description: 'Atendé primero los mantenimientos que ya pasaron su límite.',
    href: props.dashboard.links.maintenanceOverdue,
    icon: ExclamationTriangleIcon,
  },
  {
    label: 'Crear o modificar plan',
    description: 'Administrá la biblioteca preventiva y sus tareas.',
    href: props.dashboard.links.library,
    icon: ClipboardDocumentCheckIcon,
  },
].filter((action) => action.href && action.href !== '#'))

const attentionItems = computed(() => [
  {
    label: 'Mantenimientos vencidos',
    value: props.dashboard.metrics.maintenanceOverdue ?? 0,
    href: props.dashboard.links.maintenanceOverdue,
  },
  {
    label: 'Próximos a vencer',
    value: props.dashboard.metrics.maintenanceDueSoon ?? 0,
    href: props.dashboard.links.maintenanceDueSoon,
  },
  {
    label: 'Equipos sin plan',
    value: props.dashboard.metrics.equipmentWithoutPlans ?? 0,
    href: props.dashboard.links.assignPlan,
  },
  {
    label: 'Planes sin datos suficientes',
    value: props.dashboard.metrics.maintenanceMissingData ?? 0,
    href: props.dashboard.links.maintenanceMissingData,
  },
].filter((item) => item.href && item.href !== '#'))

const setupSteps = computed(() => {
  const metrics = props.dashboard.metrics
  const equipmentTotal = metrics.equipmentTotal ?? 0
  const plansConfigured = metrics.plansConfigured ?? 0
  const withoutPlans = metrics.equipmentWithoutPlans ?? 0
  const missingData = metrics.maintenanceMissingData ?? 0

  return [
    {
      label: 'Equipos cargados',
      detail: equipmentTotal > 0 ? `${equipmentTotal} equipo${equipmentTotal === 1 ? '' : 's'} cargado${equipmentTotal === 1 ? '' : 's'}` : 'Todavía no hay equipos',
      done: equipmentTotal > 0,
      href: props.dashboard.links.equipmentCreate,
    },
    {
      label: 'Planes asignados',
      detail: plansConfigured > 0 ? `${plansConfigured} plan${plansConfigured === 1 ? '' : 'es'} configurado${plansConfigured === 1 ? '' : 's'}` : 'Todavía no hay planes asignados',
      done: equipmentTotal > 0 && withoutPlans === 0 && plansConfigured > 0,
      href: props.dashboard.links.assignPlan,
    },
    {
      label: 'Datos base completos',
      detail: missingData === 0 ? 'Los planes tienen información suficiente' : `${missingData} plan${missingData === 1 ? '' : 'es'} necesita${missingData === 1 ? '' : 'n'} datos`,
      done: plansConfigured > 0 && missingData === 0,
      href: missingData > 0 ? props.dashboard.links.maintenanceMissingData : props.dashboard.links.maintenance,
    },
    {
      label: 'Control preventivo listo',
      detail: equipmentTotal > 0 && withoutPlans === 0 && missingData === 0 ? 'El sistema ya puede controlar vencimientos' : 'Completá los pasos anteriores',
      done: equipmentTotal > 0 && withoutPlans === 0 && missingData === 0,
      href: props.dashboard.links.maintenance,
    },
  ]
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

      <div class="flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <p class="text-sm font-semibold text-primary">Centro de operaciones</p>
          <h1 class="mt-1 text-balance text-2xl font-bold tracking-tight text-ink sm:text-3xl">
            Buen día, {{ firstName }}. ¿Qué necesitás hacer?
          </h1>
          <p class="mt-2 max-w-3xl text-sm leading-6 text-ink-muted sm:text-base">
            {{ dashboard.mode === 'global' ? 'Supervisá empresas, accesos y actividad general del sistema.' : 'Empezá por una acción concreta o revisá qué necesita atención hoy.' }}
          </p>
        </div>
        <a
          v-if="dashboard.links.equipment !== '#'"
          :href="dashboard.links.equipment"
          class="inline-flex min-h-11 shrink-0 items-center justify-center gap-2 self-start rounded-lg bg-primary px-4 py-2.5 text-sm font-semibold text-primary-foreground shadow-sm transition-colors hover:bg-primary-hover active:bg-primary-active sm:self-auto"
        >
          <TruckIcon class="size-5" aria-hidden="true" />
          Ver equipos
        </a>
      </div>

      <section v-if="operationalActions.length" aria-labelledby="actions-title" class="mt-7">
        <div class="flex items-end justify-between gap-4">
          <div>
            <h2 id="actions-title" class="text-lg font-bold text-ink">Acciones principales</h2>
            <p class="mt-1 text-sm text-ink-muted">No necesitás conocer los menús: elegí directamente lo que querés hacer.</p>
          </div>
        </div>
        <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
          <a
            v-for="action in operationalActions"
            :key="action.label"
            :href="action.href"
            class="group flex min-h-28 items-start gap-4 rounded-xl border border-border bg-surface p-4 shadow-sm transition hover:-translate-y-0.5 hover:border-primary/30 hover:shadow-md"
          >
            <span class="flex size-11 shrink-0 items-center justify-center rounded-lg bg-primary-subtle text-primary">
              <component :is="action.icon" class="size-6" aria-hidden="true" />
            </span>
            <span>
              <span class="block font-semibold text-ink group-hover:text-primary">{{ action.label }}</span>
              <span class="mt-1 block text-sm leading-5 text-ink-muted">{{ action.description }}</span>
            </span>
          </a>
        </div>
      </section>

      <section v-if="attentionItems.length" aria-labelledby="attention-title" class="mt-7">
        <div>
          <h2 id="attention-title" class="text-lg font-bold text-ink">Necesitan tu atención</h2>
          <p class="mt-1 text-sm text-ink-muted">Entrá directamente al problema que requiere acción.</p>
        </div>
        <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
          <a
            v-for="item in attentionItems"
            :key="item.label"
            :href="item.href"
            class="rounded-xl border border-border bg-surface p-4 shadow-sm transition hover:border-primary/30 hover:shadow-md"
          >
            <div class="text-3xl font-bold tracking-tight text-ink">{{ item.value }}</div>
            <div class="mt-1 text-sm font-medium text-ink-muted">{{ item.label }}</div>
          </a>
        </div>
      </section>

      <section aria-labelledby="setup-title" class="mt-7 rounded-xl border border-border bg-surface p-5 shadow-sm">
        <div>
          <h2 id="setup-title" class="text-lg font-bold text-ink">Configuración guiada</h2>
          <p class="mt-1 text-sm text-ink-muted">El sistema te muestra qué falta para que el mantenimiento preventivo quede operativo.</p>
        </div>
        <div class="mt-5 grid gap-3 lg:grid-cols-4">
          <a
            v-for="(step, index) in setupSteps"
            :key="step.label"
            :href="step.href"
            class="rounded-lg border p-4 transition hover:shadow-sm"
            :class="step.done ? 'border-success/30 bg-success-subtle' : 'border-border bg-canvas'"
          >
            <div class="flex items-center gap-3">
              <span
                class="flex size-8 shrink-0 items-center justify-center rounded-full text-sm font-bold"
                :class="step.done ? 'bg-success text-white' : 'bg-muted text-ink-muted'"
              >
                {{ step.done ? '✓' : index + 1 }}
              </span>
              <span class="font-semibold text-ink">{{ step.label }}</span>
            </div>
            <p class="mt-3 text-sm leading-5 text-ink-muted">{{ step.detail }}</p>
          </a>
        </div>
      </section>

      <section aria-labelledby="summary-title" class="mt-7">
        <div class="mb-4">
          <h2 id="summary-title" class="text-lg font-bold text-ink">Resumen de flota</h2>
          <p class="mt-1 text-sm text-ink-muted">Indicadores generales para supervisión.</p>
        </div>
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
          <MetricCard
            label="Equipos"
            :value="dashboard.metrics.equipmentTotal"
            tone="primary"
            :href="dashboard.links.equipment"
            link-label="Ver flota"
          />
          <MetricCard
            label="Próximos"
            :value="dashboard.metrics.maintenanceDueSoon"
            tone="due"
            :href="dashboard.links.maintenanceDueSoon"
            link-label="Revisar próximos"
          />
          <MetricCard
            label="Vencidos"
            :value="dashboard.metrics.maintenanceOverdue"
            tone="overdue"
            :href="dashboard.links.maintenanceOverdue"
            link-label="Atender vencidos"
            class="sm:col-span-2 xl:col-span-1"
          />
        </div>
      </section>

      <div class="mt-6">
        <UpcomingMaintenance
          :items="dashboard.upcomingMaintenance"
          :maintenance-url="dashboard.links.maintenance"
        />
      </div>
    </template>
  </ApplicationShell>
</template>
