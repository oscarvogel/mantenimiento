import { createApp, markRaw } from 'vue'
import PageHost from './PageHost.vue'
import { normalizeAppShellPayload, normalizeDashboardPayload } from './adapters/dashboardPayload.js'
import { developmentDashboard } from './data/developmentDashboard.js'
import LoginPage from './pages/login/LoginPage.vue'
import { loadFrontendProfile } from './profiles/registry.js'
import { installContextualReadingActions } from './ui/contextualReadingActions.js'
import { installEquipmentComboboxes } from './ui/equipmentCombobox.js'
import { installEquipmentAssignedPlans } from './ui/equipmentAssignedPlans.js'
import { installLibraryTaskStatus } from './ui/libraryTaskStatus.js'
import { installPreventiveOrderFlow } from './ui/preventiveOrderFlow.js'
import { installQuickPlanAssignment } from './ui/quickPlanAssignment.js'
import { installTemplateServicePicker } from './ui/templateServicePicker.js'
import { consumeFlash, installGlobalBehaviors } from './ui/globals.js'
import { installScrollReveal } from './ui/scrollReveal.js'
import { initializeTheme } from './ui/theme.js'
import './styles.css'

initializeTheme()
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

export async function mountMaintenanceDashboard(element, payload) {
  if (!element) return null

  const page = typeof payload?.page === 'string' ? payload.page : 'dashboard'
  if (page === 'login') {
    const pageData = payload?.data && typeof payload.data === 'object' ? payload.data : {}
    const app = createApp(LoginPage, { data: pageData })
    installScrollReveal(app)
    return app.mount(element)
  }

  const shell = normalizeAppShellPayload(payload)
  const profile = await loadFrontendProfile(shell.presentation.profile)

  if (page !== 'dashboard') {
    const pageComponent = profile.resolvePage(page)

    if (pageComponent) {
      const pageData = payload?.data && typeof payload.data === 'object'
        ? payload.data
        : payload?.pagePayload ?? {}
      const pageProps = page === 'reports'
        ? { data: pageData.report ?? pageData.data ?? {}, urls: pageData.urls ?? {} }
        : { data: pageData }

      const app = createApp(PageHost, {
        shell,
        shellComponent: markRaw(profile.shellComponent),
        pageComponent: markRaw(pageComponent),
        pageProps,
      })
      installScrollReveal(app)
      return app.mount(element)
    }
  }

  const dashboard = normalizeDashboardPayload(payload)
  const dashboardComponent = profile.dashboardComponents[dashboard.view] ?? profile.dashboardComponents.operational

  const app = createApp(dashboardComponent, { dashboard, shellComponent: markRaw(profile.shellComponent) })
  installScrollReveal(app)
  return app.mount(element)
}

const root = document.getElementById('maintenance-app')
  ?? document.getElementById('maintenance-dashboard')
if (root) {
  const serverPayload = payloadFromDocument()
  const payload = serverPayload ?? (import.meta.env.DEV ? developmentDashboard : null)
  void mountMaintenanceDashboard(root, payload).then(() => {
    if (payload?.page === 'preventive-plans') {
      installEquipmentComboboxes(root, payload?.data?.catalogs?.equipment ?? [])
      installTemplateServicePicker(root, payload?.data?.catalogs ?? {})
      installPreventiveOrderFlow(root, payload)
    }
    if (['equipment-detail', 'assets-index'].includes(payload?.page)) {
      installQuickPlanAssignment(root, payload)
      installContextualReadingActions(root, payload)
    }
    if (payload?.page === 'equipment-detail') {
      installEquipmentAssignedPlans(root, payload)
    }
    if (payload?.page === 'preventive-library') {
      installLibraryTaskStatus(root, payload)
    }
    if (serverPayload) consumeFlash(serverPayload.data?.flash)
  })
}

if ('serviceWorker' in navigator) {
  const manifest = document.querySelector('link[rel="manifest"]')
  if (manifest?.href) {
    const serviceWorkerUrl = new URL('service-worker.js', manifest.href)
    const scope = new URL('./', manifest.href).pathname
    navigator.serviceWorker.register(serviceWorkerUrl, { scope }).catch(() => {})
  }
}
