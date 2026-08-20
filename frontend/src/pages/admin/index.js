import BranchesAdminPage from './BranchesAdminPage.vue'
import SuperAdminPage from './SuperAdminPage.vue'
import SuperAdminDemoPage from './SuperAdminDemoPage.vue'
import UsersAdminPage from './UsersAdminPage.vue'

export { BranchesAdminPage, SuperAdminPage, SuperAdminDemoPage, UsersAdminPage }

export const adminPagesByType = Object.freeze({
  superadmin: SuperAdminDemoPage,
  'branches-admin': BranchesAdminPage,
  'users-admin': UsersAdminPage,
})
