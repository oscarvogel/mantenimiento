import { createApp } from 'vue'
import App from './App.vue'
import PageHost from './PageHost.vue'
import { normalizeAppShellPayload, normalizeDashboardPayload } from './adapters/dashboardPayload.js'
import { developmentDashboard } from './data/developmentDashboard.js'
import { operationPageComponents } from './pages/operations/index.js'
import { adminPagesByType } from './pages/admin/index.js'
import { ReportsPage } from './pages/reports/index.js'
import { NotificationCenterPage } from './pages/notifications/index.js'
import LoginPage from './pages/login/LoginPage.vue'
import { installEquipmentComboboxes } from './ui/equipmentCombobox.js'
import { installEquipmentAssignedPlans } from './ui/equipmentAssignedPlans.js'
import { installQuickPlanAssignment } from './ui/quickPlanAssignment.js'
import { installTemplateServicePicker } from './ui/templateServicePicker.js'
import { consumeFlash, installGlobalBehaviors } from './ui/globals.js'
import './styles.css'

installGlobalBehaviors()

function payloadFromDocument() {
  if (window.__MAINTENANCE_DASHBOARD__ && typeof window.__MAINTENANCE_DASHBOARD__ === 'object') {
    return window.__MAINTENANCE_DASHBOARD__
  }

  const payloadElement = document.getElementById('maintenance-app-data')
    ?? document.getElementById('maintenance-dashboard-data')
  if (!payloadElement?.textContent) return null

  try {
    return JSON.parse(payloadElement.textContent)
  } catch {
    return null
  }
}

export function mountMaintenanceDashboard(element, payload) {
  if (!element) return null

  const page = typeof payload?.page === 'string' ? payload.page : 'dashboard'
  if (page === 'login') {
    const pageData = payload?.data && typeof payload.data === 'object' ? payload.data : {}
    return createApp(LoginPage, { data: pageData }).mount(element)
  }

  if (page !== 'dashboard') {
    const pageComponent = operationPageComponents[page]
      ?? adminPagesByType[page]
      ?? (page === 'reports' ? ReportsPage : null)
      ?? (page === 'notifications' ? NotificationCenterPage : null)

    if (pageComponent) {
      const pageData = payload?.data && typeof payload.data === 'object'
        ? payload.data
        : payload?.pagePayload ?? {}
      const pageProps = page === 'reports'
        ? { data: pageData.report ?? pageData.data ?? {}, urls: pageData.urls ?? {} }
        : { data: pageData }

      return createApp(PageHost, {
        shell: normalizeAppShellPayload(payload),
        pageComponent,
        pageProps,
      }).mount(element)
    }
  }

  return createApp(App, {
    dashboard: normalizeDashboardPayload(payload),
  }).mount(element)
}

const root = document.getElementById('maintenance-app')
  ?? document.getElementById('maintenance-dashboard')
if (root) {
  const serverPayload = payloadFromDocument()
  const payload = serverPayload ?? (import.meta.env.DEV ? developmentDashboard : null)
  mountMaintenanceDashboard(root, payload)
  if (payload?.page === 'preventive-plans') {
    installEquipmentComboboxes(root, payload?.data?.catalogs?.equipment ?? [])
    installTemplateServicePicker(root, payload?.data?.catalogs ?? {})
  }
  if (['equipment-detail', 'assets-index'].includes(payload?.page)) {
    installQuickPlanAssignment(root, payload)
  }
  if (payload?.page === 'equipment-detail') {
    installEquipmentAssignedPlans(root, payload)
  }
  if (serverPayload) consumeFlash(serverPayload.data?.flash)
}

if ('serviceWorker' in navigator) {
  const manifest = document.querySelector('link[rel="manifest"]')
  if (manifest?.href) {
    const serviceWorkerUrl = new URL('service-worker.js', manifest.href)
    const scope = new URL('./', manifest.href).pathname
    navigator.serviceWorker.register(serviceWorkerUrl, { scope }).catch(() => {})
  }
}
