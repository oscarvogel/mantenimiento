import AssetsIndexPage from './AssetsIndexPage.vue'
import EquipmentDetailPage from './EquipmentDetailPage.vue'
import ImportsIndexPage from './ImportsIndexPage.vue'
import ImportsShowPage from './ImportsShowPage.vue'
import MaintenanceOverviewPage from './MaintenanceOverviewPage.vue'
import MaintenanceServicesPage from './MaintenanceServicesPage.vue'
import PreventiveLibraryPage from './PreventiveLibraryPage.vue'
import PreventivePlansPage from './PreventivePlansPage.vue'
import QuickReadingsPage from './QuickReadingsPage.vue'
import WorkOrdersIndexPage from './WorkOrdersIndexPage.vue'

export {
  AssetsIndexPage,
  EquipmentDetailPage,
  ImportsIndexPage,
  ImportsShowPage,
  MaintenanceOverviewPage,
  MaintenanceServicesPage,
  PreventiveLibraryPage,
  PreventivePlansPage,
  QuickReadingsPage,
  WorkOrdersIndexPage,
}

export const operationPageComponents = Object.freeze({
  'maintenance-overview': MaintenanceOverviewPage,
  'maintenance-services': MaintenanceServicesPage,
  'preventive-plans': PreventivePlansPage,
  'equipment-detail': EquipmentDetailPage,
  'assets-index': AssetsIndexPage,
  'imports-index': ImportsIndexPage,
  'imports-show': ImportsShowPage,
  'preventive-library': PreventiveLibraryPage,
  'quick-readings': QuickReadingsPage,
  'work-orders-index': WorkOrdersIndexPage,
})

export function resolveOperationPage(pageType) {
  return operationPageComponents[pageType] ?? null
}
