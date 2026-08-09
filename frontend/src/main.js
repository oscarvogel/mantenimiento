import { createApp } from 'vue'
import App from './App.vue'
import PageHost from './PageHost.vue'
import { normalizeAppShellPayload, normalizeDashboardPayload } from './adapters/dashboardPayload.js'
import { developmentDashboard } from './data/developmentDashboard.js'
import { operationPageComponents } from './pages/operations/index.js'
import { adminPagesByType } from './pages/admin/index.js'
import { ReportsPage } from './pages/reports/index.js'
import LoginPage from './pages/login/LoginPage.vue'
import './styles.css'

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
}
