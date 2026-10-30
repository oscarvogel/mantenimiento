import BranchesAdminPage from './BranchesAdminPage.vue'
import ChatAuditPage from './ChatAuditPage.vue'
import NotificationSettingsPage from './NotificationSettingsPage.vue'
import LocalityCatalogImportPage from './LocalityCatalogImportPage.vue'
import SuperAdminPage from './SuperAdminPage.vue'
import SuperAdminDemoPage from './SuperAdminDemoPage.vue'
import UsersAdminPage from './UsersAdminPage.vue'
import UsersAdminWithAuditPage from './UsersAdminWithAuditPage.vue'
import TelemetrySamplesAdminPage from './TelemetrySamplesAdminPage.vue'
import WhatsAppDigestPreviewPage from './WhatsAppDigestPreviewPage.vue'

export { BranchesAdminPage, ChatAuditPage, LocalityCatalogImportPage, NotificationSettingsPage, SuperAdminPage, SuperAdminDemoPage, TelemetrySamplesAdminPage, UsersAdminPage, UsersAdminWithAuditPage, WhatsAppDigestPreviewPage }

export const adminPagesByType = Object.freeze({
  superadmin: SuperAdminDemoPage,
  'branches-admin': BranchesAdminPage,
  'users-admin': UsersAdminWithAuditPage,
  'chatbot-audit': ChatAuditPage,
  'superadmin-notification-settings': NotificationSettingsPage,
  'telemetry-samples-admin': TelemetrySamplesAdminPage,
  'whatsapp-digest-preview': WhatsAppDigestPreviewPage,
  'locality-catalog-import': LocalityCatalogImportPage,
})
