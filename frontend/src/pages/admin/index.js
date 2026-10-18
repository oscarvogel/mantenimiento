import BranchesAdminPage from './BranchesAdminPage.vue'
import CustomerImportsPage from './CustomerImportsPage.vue'
import ChatAuditPage from './ChatAuditPage.vue'
import NotificationSettingsPage from './NotificationSettingsPage.vue'
import SuperAdminPage from './SuperAdminPage.vue'
import SuperAdminDemoPage from './SuperAdminDemoPage.vue'
import UsersAdminPage from './UsersAdminPage.vue'
import UsersAdminWithAuditPage from './UsersAdminWithAuditPage.vue'
import TelemetrySamplesAdminPage from './TelemetrySamplesAdminPage.vue'
import TripImportsPage from './TripImportsPage.vue'

export { CustomerImportsPage, BranchesAdminPage, ChatAuditPage, NotificationSettingsPage, SuperAdminPage, SuperAdminDemoPage, TelemetrySamplesAdminPage, TripImportsPage, UsersAdminPage, UsersAdminWithAuditPage }

export const adminPagesByType = Object.freeze({
  'customer-imports': CustomerImportsPage,
  'trip-imports': TripImportsPage,
  superadmin: SuperAdminDemoPage,
  'branches-admin': BranchesAdminPage,
  'users-admin': UsersAdminWithAuditPage,
  'chatbot-audit': ChatAuditPage,
  'superadmin-notification-settings': NotificationSettingsPage,
  'telemetry-samples-admin': TelemetrySamplesAdminPage,
})
