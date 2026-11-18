import AssetsIndexPage from './AssetsIndexPage.vue'
import EquipmentDetailPage from './EquipmentDetailPage.vue'
import EquipmentDetailHistoryEvidencePage from './EquipmentDetailHistoryEvidencePage.vue'
import TelemetryIntegrationSettingsPage from './TelemetryIntegrationSettingsPage.vue'
import FleetTelemetryPage from './FleetTelemetryPage.vue'
import FleetConsumptionPage from './FleetConsumptionPage.vue'
import EquipmentOperatePage from './EquipmentOperatePage.vue'
import EquipmentCatalogsMasterPage from './EquipmentCatalogsMasterPage.vue'
import DriverAssignmentsPreviewPage from './DriverAssignmentsPreviewPage.vue'
import EmployeesIndexPage from './EmployeesIndexPage.vue'
import ExpirationTypesMasterPage from './ExpirationTypesMasterPage.vue'
import ExpirationsIndexPage from './ExpirationsIndexPage.vue'
import ImportsIndexPage from './ImportsIndexPage.vue'
import ImportsShowPage from './ImportsShowPage.vue'
import MaintenanceOverviewPage from './MaintenanceOverviewPage.vue'
import MaintenanceServicesPage from './MaintenanceServicesPage.vue'
import PreventiveLibraryPage from './PreventiveLibraryPage.vue'
import PreventivePlansPage from './PreventivePlansPage.vue'
import ProvidersIndexPage from './ProvidersIndexPage.vue'
import QuickReadingsPage from './QuickReadingsPage.vue'
import ReadingControlPage from './ReadingControlPage.vue'
import RegisterReadingPage from './RegisterReadingPage.vue'
import WorkOrderDocumentImportPage from './WorkOrderDocumentImportPage.vue'
import WorkOrdersIndexPage from './WorkOrdersIndexPage.vue'
import WorkRequestsIndexPage from './WorkRequestsIndexPage.vue'
import TripMastersPage from './TripMastersPage.vue'
import TripsDashboardPage from './TripsDashboardPage.vue'
import TripDetailPage from './TripDetailPage.vue'
import TripOperationsDashboardPage from './TripOperationsDashboardPage.vue'
import TripFuelPendingPage from './TripFuelPendingPage.vue'
import TripOperationsIndexPage from './TripOperationsIndexPage.vue'
import TripOperationsCreatePage from './TripOperationsCreatePage.vue'
import TripOperationsDetailPage from './TripOperationsDetailPage.vue'
import TripDriverSettlementPage from './TripDriverSettlementPage.vue'
import TripProfitabilityPage from './TripProfitabilityPage.vue'
import TripCommercialClosingPage from './TripCommercialClosingPage.vue'
import CustomerAccountsPage from './CustomerAccountsPage.vue'
import CustomerAccountDetailPage from './CustomerAccountDetailPage.vue'

export {
  AssetsIndexPage,
  EquipmentDetailPage,
  EquipmentDetailHistoryEvidencePage,
  TelemetryIntegrationSettingsPage,
  FleetTelemetryPage,
  FleetConsumptionPage,
  EquipmentOperatePage,
  EquipmentCatalogsMasterPage,
  DriverAssignmentsPreviewPage,
  EmployeesIndexPage,
  ExpirationTypesMasterPage,
  ExpirationsIndexPage,
  ImportsIndexPage,
  ImportsShowPage,
  MaintenanceOverviewPage,
  MaintenanceServicesPage,
  PreventiveLibraryPage,
  PreventivePlansPage,
  ProvidersIndexPage,
  QuickReadingsPage,
  ReadingControlPage,
  RegisterReadingPage,
  WorkOrderDocumentImportPage,
  WorkOrdersIndexPage,
  WorkRequestsIndexPage,
  TripMastersPage,
  TripsDashboardPage,
  TripDetailPage,
  TripOperationsDashboardPage,
  TripFuelPendingPage,
  TripOperationsIndexPage,
  TripOperationsCreatePage,
  TripOperationsDetailPage,
  TripDriverSettlementPage,
  TripProfitabilityPage,
  TripCommercialClosingPage,
  CustomerAccountsPage,
  CustomerAccountDetailPage,
}

export const operationPageComponents = Object.freeze({
  'maintenance-overview': MaintenanceOverviewPage,
  'maintenance-services': MaintenanceServicesPage,
  'preventive-plans': PreventivePlansPage,
  'equipment-detail': EquipmentDetailPage,
  'telemetry-integrations': TelemetryIntegrationSettingsPage,
  'fleet-telemetry': FleetTelemetryPage,
  'fleet-consumption': FleetConsumptionPage,
  'equipment-operate': EquipmentOperatePage,
  'equipment-catalogs-master': EquipmentCatalogsMasterPage,
  'driver-assignments-preview': DriverAssignmentsPreviewPage,
  'employees-index': EmployeesIndexPage,
  'expiration-types-master': ExpirationTypesMasterPage,
  'expirations-index': ExpirationsIndexPage,
  'assets-index': AssetsIndexPage,
  'imports-index': ImportsIndexPage,
  'imports-show': ImportsShowPage,
  'preventive-library': PreventiveLibraryPage,
  'quick-readings': QuickReadingsPage,
  'reading-control': ReadingControlPage,
  'work-order-document-import': WorkOrderDocumentImportPage,
  'work-orders-index': WorkOrdersIndexPage,
  'work-requests-index': WorkRequestsIndexPage,
  'providers-index': ProvidersIndexPage,
  'trip-masters': TripMastersPage,
  'trips-dashboard': TripsDashboardPage,
  'trip-detail': TripDetailPage,
  'trips-operations-dashboard': TripOperationsDashboardPage,
  'trips-fuel-pending': TripFuelPendingPage,
  'trips-operations': TripOperationsIndexPage,
  'trip-operations-create': TripOperationsCreatePage,
  'trip-operations-detail': TripOperationsDetailPage,
  'trips-commercial-closing': TripCommercialClosingPage,
  'customer-accounts': CustomerAccountsPage,
  'customer-account-detail': CustomerAccountDetailPage,
  'trip-driver-settlement': TripDriverSettlementPage,
  'trips-profitability': TripProfitabilityPage,
})

export function resolveOperationPage(pageType) {
  return operationPageComponents[pageType] ?? null
}
