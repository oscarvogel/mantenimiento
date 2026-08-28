<script setup>
import { computed } from 'vue'
import ApplicationShell from './components/ApplicationShell.vue'
import DashboardLoading from './components/DashboardLoading.vue'
import ManagerialDashboard from './components/ManagerialDashboard.vue'

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
      <ManagerialDashboard :dashboard="dashboard" :first-name="firstName" />
    </template>
  </ApplicationShell>
</template>
