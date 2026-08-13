import AssetsIndexPage from './AssetsIndexPage.vue'
import EquipmentDetailPage from './EquipmentDetailPage.vue'
import ImportsIndexPage from './ImportsIndexPage.vue'
import ImportsShowPage from './ImportsShowPage.vue'
import MaintenanceOverviewPage from './MaintenanceOverviewPage.vue'
import PreventiveLibraryPage from './PreventiveLibraryPage.vue'
import PreventivePlansPage from './PreventivePlansPage.vue'
import QuickReadingsPage from './QuickReadingsPage.vue'

export {
  AssetsIndexPage,
  EquipmentDetailPage,
  ImportsIndexPage,
  ImportsShowPage,
  MaintenanceOverviewPage,
  PreventiveLibraryPage,
  PreventivePlansPage,
  QuickReadingsPage,
}

export const operationPageComponents = Object.freeze({
  'maintenance-overview': MaintenanceOverviewPage,
  'preventive-plans': PreventivePlansPage,
  'equipment-detail': EquipmentDetailPage,
  'assets-index': AssetsIndexPage,
  'imports-index': ImportsIndexPage,
  'imports-show': ImportsShowPage,
  'preventive-library': PreventiveLibraryPage,
  'quick-readings': QuickReadingsPage,
})

export function resolveOperationPage(pageType) {
  return operationPageComponents[pageType] ?? null
}
