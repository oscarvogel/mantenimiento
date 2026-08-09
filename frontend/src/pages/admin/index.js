import BranchesAdminPage from './BranchesAdminPage.vue'
import SuperAdminPage from './SuperAdminPage.vue'
import UsersAdminPage from './UsersAdminPage.vue'

export { BranchesAdminPage, SuperAdminPage, UsersAdminPage }

export const adminPagesByType = Object.freeze({
  superadmin: SuperAdminPage,
  'branches-admin': BranchesAdminPage,
  'users-admin': UsersAdminPage,
})
