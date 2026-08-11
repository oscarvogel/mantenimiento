import AssetsIndexPage from './AssetsIndexPage.vue'
import EquipmentDetailPage from './EquipmentDetailPage.vue'
import ImportsIndexPage from './ImportsIndexPage.vue'
import ImportsShowPage from './ImportsShowPage.vue'
import MaintenanceOverviewPage from './MaintenanceOverviewPage.vue'
import PreventiveLibraryPage from './PreventiveLibraryPage.vue'

export {
  AssetsIndexPage,
  EquipmentDetailPage,
  ImportsIndexPage,
  ImportsShowPage,
  MaintenanceOverviewPage,
  PreventiveLibraryPage,
}

export const operationPageComponents = Object.freeze({
  'maintenance-overview': MaintenanceOverviewPage,
  'equipment-detail': EquipmentDetailPage,
  'assets-index': AssetsIndexPage,
  'imports-index': ImportsIndexPage,
  'imports-show': ImportsShowPage,
  'preventive-library': PreventiveLibraryPage,
})

export function resolveOperationPage(pageType) {
  return operationPageComponents[pageType] ?? null
}
