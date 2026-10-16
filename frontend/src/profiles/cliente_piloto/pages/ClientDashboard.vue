<script setup>
import { computed } from 'vue'
import { ArrowUpRightIcon, ClipboardDocumentListIcon, ClockIcon, ExclamationTriangleIcon, WrenchScrewdriverIcon } from '@heroicons/vue/24/outline'
import ClientShell from '../shell/ClientShell.vue'

const props = defineProps({
  dashboard: { type: Object, required: true },
  loading: { type: Boolean, default: false },
  shellComponent: { type: Object, default: ClientShell },
})

const shell = computed(() => ({
  user: props.dashboard.user,
  company: props.dashboard.company,
  navigation: props.dashboard.navigation,
  notifications: props.dashboard.notifications,
  homeUrl: props.dashboard.homeUrl,
  moduleNavigation: props.dashboard.moduleNavigation,
  logout: props.dashboard.logout,
}))

const primaryKpis = computed(() => [
  {
    key: 'maintenanceOverdue', label: 'Preventivos vencidos', detail: 'Requieren atención',
    value: props.dashboard.metrics.maintenanceOverdue, href: props.dashboard.links.maintenanceOverdue,
    action: 'Revisar vencidos', tone: 'danger', icon: ExclamationTriangleIcon,
  },
  {
    key: 'maintenanceDueSoon', label: 'Próximos a vencer', detail: 'Dentro del período de anticipación',
    value: props.dashboard.metrics.maintenanceDueSoon, href: props.dashboard.links.maintenanceDueSoon,
    action: 'Ver próximos', tone: 'amber', icon: ClockIcon,
  },
  {
    key: 'preventiveCompliance', label: 'Cumplimiento preventivo', detail: 'Planes preventivos al día',
    value: props.dashboard.metrics.preventiveCompliance,
    formatted: props.dashboard.metricAvailability?.preventiveCompliance
      ? `${props.dashboard.metrics.preventiveCompliance}%`
      : null,
    href: props.dashboard.links.maintenance, action: 'Ver planes', tone: 'green', icon: WrenchScrewdriverIcon,
  },
  {
    key: 'openOrders', label: 'Órdenes abiertas', detail: 'Trabajo pendiente de cierre',
    value: props.dashboard.metrics.openOrders, href: props.dashboard.links.orders,
    action: 'Ver órdenes', tone: 'blue', icon: ClipboardDocumentListIcon,
  },
])

const attentionKpis = computed(() => [
  {
    key: 'maintenanceMissingData', label: 'Planes sin datos base', detail: 'Falta una lectura inicial',
    value: props.dashboard.metrics.maintenanceMissingData, href: props.dashboard.links.maintenanceMissingData,
  },
  {
    key: 'equipmentWithoutReading', label: 'Equipos sin lecturas', detail: 'Registrar km u horas de uso',
    value: props.dashboard.metrics.equipmentWithoutReading, href: props.dashboard.links.quickReadings,
  },
  {
    key: 'equipmentWithStaleReading', label: 'Lecturas desactualizadas',
    detail: `Sin actualizar en ${props.dashboard.metrics.staleReadingDays} días o más`,
    value: props.dashboard.metrics.equipmentWithStaleReading, href: props.dashboard.links.quickReadings,
  },
])

const metricText = (key, value) => {
  if (!props.dashboard.metricAvailability?.[key]) return 'Sin datos suficientes'
  return key === 'preventiveCompliance' ? `${value}%` : new Intl.NumberFormat('es-AR').format(value)
}
</script>

<template>
  <component :is="shellComponent" :shell="shell">
    <div v-if="loading" class="client-pilot-profile__loading" role="status">Cargando indicadores de mantenimiento…</div>
    <template v-else>
      <header class="client-pilot-profile__heading">
        <div>
          <p class="client-pilot-profile__eyebrow">Mantenimiento · {{ dashboard.company.name }}</p>
          <h1>Estado de mantenimiento</h1>
          <p class="client-pilot-profile__intro">Una vista rápida de los trabajos y preventivos que requieren seguimiento.</p>
        </div>
        <a v-if="dashboard.links.quickReadings !== '#'" class="client-pilot-profile__primary-action" :href="dashboard.links.quickReadings">
          Registrar lectura <ArrowUpRightIcon aria-hidden="true" />
        </a>
      </header>

      <section aria-labelledby="client-pilot-priority-title" class="client-pilot-profile__section">
        <div class="client-pilot-profile__section-heading">
          <div>
            <p class="client-pilot-profile__eyebrow">Resumen operativo</p>
            <h2 id="client-pilot-priority-title">Prioridades de mantenimiento</h2>
          </div>
          <span class="client-pilot-profile__section-note">Indicadores disponibles en el sistema</span>
        </div>
        <div class="client-pilot-profile__primary-grid">
          <article v-for="item in primaryKpis" :key="item.key" class="client-pilot-profile__metric-card" :class="`is-${item.tone}`" data-testid="maintenance-kpi" :data-kpi="item.key">
            <div class="client-pilot-profile__metric-top">
              <span class="client-pilot-profile__metric-icon"><component :is="item.icon" aria-hidden="true" /></span>
              <span class="client-pilot-profile__metric-label">{{ item.label }}</span>
            </div>
            <p class="client-pilot-profile__metric-value" :class="{ 'is-unavailable': !dashboard.metricAvailability?.[item.key] }">
              {{ item.formatted ?? metricText(item.key, item.value) }}
            </p>
            <p class="client-pilot-profile__metric-detail">{{ item.detail }}</p>
            <a v-if="item.href !== '#'" class="client-pilot-profile__metric-action" :href="item.href">
              {{ item.action }} <ArrowUpRightIcon aria-hidden="true" />
            </a>
          </article>
        </div>
      </section>

      <section aria-labelledby="client-pilot-data-title" class="client-pilot-profile__section client-pilot-profile__attention">
        <div class="client-pilot-profile__section-heading">
          <div>
            <p class="client-pilot-profile__eyebrow">Calidad de seguimiento</p>
            <h2 id="client-pilot-data-title">Lecturas y datos pendientes</h2>
          </div>
        </div>
        <div class="client-pilot-profile__attention-grid">
          <article v-for="item in attentionKpis" :key="item.key" class="client-pilot-profile__attention-card" data-testid="maintenance-kpi" :data-kpi="item.key">
            <div>
              <h3>{{ item.label }}</h3>
              <p>{{ item.detail }}</p>
            </div>
            <strong :class="{ 'is-unavailable': !dashboard.metricAvailability?.[item.key] }">{{ metricText(item.key, item.value) }}</strong>
            <a v-if="item.href !== '#'" :href="item.href">Revisar <ArrowUpRightIcon aria-hidden="true" /></a>
          </article>
        </div>
      </section>
    </template>
  </component>
</template>
