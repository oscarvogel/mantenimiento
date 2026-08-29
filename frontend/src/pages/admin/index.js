import BranchesAdminPage from './BranchesAdminPage.vue'
import ChatAuditPage from './ChatAuditPage.vue'
import SuperAdminPage from './SuperAdminPage.vue'
import SuperAdminDemoPage from './SuperAdminDemoPage.vue'
import UsersAdminPage from './UsersAdminPage.vue'
import UsersAdminWithAuditPage from './UsersAdminWithAuditPage.vue'

export { BranchesAdminPage, ChatAuditPage, SuperAdminPage, SuperAdminDemoPage, UsersAdminPage, UsersAdminWithAuditPage }

export const adminPagesByType = Object.freeze({
  superadmin: SuperAdminDemoPage,
  'branches-admin': BranchesAdminPage,
  'users-admin': UsersAdminWithAuditPage,
  'chatbot-audit': ChatAuditPage,
})
