import AssetsIndexPage from './AssetsIndexPage.vue'
import EquipmentDetailPage from './EquipmentDetailPage.vue'
import ImportsIndexPage from './ImportsIndexPage.vue'
import ImportsShowPage from './ImportsShowPage.vue'
import MaintenanceOverviewPage from './MaintenanceOverviewPage.vue'
import PreventivePlansPage from './PreventivePlansPage.vue'

export {
  AssetsIndexPage,
  EquipmentDetailPage,
  ImportsIndexPage,
  ImportsShowPage,
  MaintenanceOverviewPage,
  PreventivePlansPage,
}

export const operationPageComponents = Object.freeze({
  'maintenance-overview': MaintenanceOverviewPage,
  'preventive-plans': PreventivePlansPage,
  'equipment-detail': EquipmentDetailPage,
  'assets-index': AssetsIndexPage,
  'imports-index': ImportsIndexPage,
  'imports-show': ImportsShowPage,
  'preventive-library': PreventiveLibraryPage,
})

export function resolveOperationPage(pageType) {
  return operationPageComponents[pageType] ?? null
}
