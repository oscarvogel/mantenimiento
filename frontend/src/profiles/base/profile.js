import App from '../../App.vue'
import ManagerialApp from '../../ManagerialApp.vue'
import ApplicationShell from '../../components/ApplicationShell.vue'
import { operationPageComponents } from '../../pages/operations/index.js'
import { adminPagesByType } from '../../pages/admin/index.js'
import { managerialPagesByType } from '../../pages/gerencia/index.js'
import { ReportsPage } from '../../pages/reports/index.js'
import { NotificationCenterPage } from '../../pages/notifications/index.js'
import CommandCenterPage from '../../pages/platform/CommandCenterPage.vue'

const basePages = Object.freeze({
  ...operationPageComponents,
  ...adminPagesByType,
  ...managerialPagesByType,
  reports: ReportsPage,
  notifications: NotificationCenterPage,
  'command-center': CommandCenterPage,
})

const dashboardComponents = Object.freeze({
  operational: App,
  managerial: ManagerialApp,
})

export default Object.freeze({
  key: 'base',
  shellComponent: ApplicationShell,
  resolvePage(pageKey, defaults = {}) {
    return basePages[pageKey] ?? defaults[pageKey] ?? null
  },
  dashboardComponents,
  styles: Object.freeze([]),
})
