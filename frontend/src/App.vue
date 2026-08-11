<script setup>
import { computed } from 'vue'
import { TruckIcon } from '@heroicons/vue/24/outline'
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
          <p class="text-sm font-semibold text-primary">Resumen operativo</p>
          <h1 class="mt-1 text-balance text-2xl font-bold tracking-tight text-ink sm:text-3xl">
            Buen día, {{ firstName }}
          </h1>
          <p class="mt-2 max-w-2xl text-sm leading-6 text-ink-muted sm:text-base">
            {{ dashboard.mode === 'global' ? 'Supervisá empresas, accesos y actividad general del sistema.' : 'Este es el estado actual de tus equipos y próximos servicios.' }}
          </p>
        </div>
        <a
          v-if="dashboard.links.equipment !== '#'"
          :href="dashboard.links.equipment"
          class="inline-flex min-h-11 shrink-0 items-center justify-center gap-2 self-start rounded-lg bg-primary px-4 py-2.5 text-sm font-semibold text-primary-foreground shadow-sm transition-colors hover:bg-primary-hover active:bg-primary-active sm:self-auto"
        >
          <TruckIcon class="size-5" aria-hidden="true" />
          Ver camiones
        </a>
      </div>

      <section aria-labelledby="summary-title" class="mt-7">
        <h2 id="summary-title" class="sr-only">Resumen de flota</h2>
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
          <MetricCard
            label="Camiones"
            :value="dashboard.metrics.equipmentTotal"
            tone="primary"
            :href="dashboard.links.equipment"
            link-label="Ver flota"
          />
          <MetricCard
            label="Próximos"
            :value="dashboard.metrics.maintenanceDueSoon"
            tone="due"
            :href="dashboard.links.maintenance"
            link-label="Revisar próximos"
          />
          <MetricCard
            label="Vencidos"
            :value="dashboard.metrics.maintenanceOverdue"
            tone="overdue"
            :href="dashboard.links.maintenance"
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
