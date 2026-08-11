<?php

namespace Config;

use App\Application\Identity\Port\LoginAttemptLimiter;
use App\Application\AppShell\GetAppShellContext;
use App\Application\Dashboard\GetMaintenanceDashboard;
use App\Application\Importations\CancelImportHandler;
use App\Application\Importations\ConfirmImportHandler;
use App\Application\Importations\CreateImportDraftHandler;
use App\Application\Importations\GenerateImportTemplateHandler;
use App\Application\Importations\GetImportPreviewHandler;
use App\Application\Importations\ImportRowValidator;
use App\Application\Importations\ListImportsHandler;
use App\Application\Assets\CreateEquipmentHandler;
use App\Application\Assets\AssetCatalogService;
use App\Application\Assets\CreateEquipmentRelationHandler;
use App\Application\Assets\FinishEquipmentRelationHandler;
use App\Application\Assets\GetEquipmentQrPayload;
use App\Application\Assets\ListEquipment;
use App\Application\Assets\RenderEquipmentQr;
use App\Application\Assets\Attachment\DownloadEquipmentAttachmentHandler;
use App\Application\Assets\Attachment\ListEquipmentAttachmentsHandler;
use App\Application\Assets\Attachment\RetireEquipmentAttachmentHandler;
use App\Application\Assets\Attachment\UploadEquipmentAttachmentHandler;
use App\Application\Assets\DecommissionEquipmentHandler;
use App\Application\Assets\GetEquipmentDetails;
use App\Application\Assets\TransferEquipmentHandler;
use App\Application\Assets\UpdateEquipmentHandler;
use App\Application\Measurement\CorrectReadingHandler;
use App\Application\Measurement\ListReadingHistoryHandler;
use App\Application\Measurement\RegisterReadingHandler;
use App\Application\MaintenanceCircuit\DetectOverduePlans;
use App\Application\MaintenanceCircuit\GetCircuitOverview;
use App\Application\MaintenanceCircuit\GeneratePreventiveOrderFromNotice;
use App\Application\MaintenanceCircuit\ClosePreventiveOrder;
use App\Application\PreventiveMaintenance\AsignarPlan;
use App\Application\PreventiveMaintenance\ConsultarVencimientos;
use App\Application\PreventiveMaintenance\MaterializarAvisoVencido;
use App\Application\PreventiveMaintenance\RecalcularPlanTrasCierre;
use App\Application\WorkOrders\StartWorkOrder;
use App\Application\Reports\GetMaintenanceReport;
use App\Application\Organization\AssignUserCompanyHandler;
use App\Application\Organization\AssignUserRolesHandler;
use App\Application\Organization\CreateCompanyHandler;
use App\Application\Organization\CreateCompanyAdministratorHandler;
use App\Application\Organization\GetOrganizationOverview;
use App\Application\Organization\Port\OrganizationAdministrationPort;
use App\Application\Organization\Port\TenantAdministrationPort;
use App\Application\Organization\TenantAdministrationService;
use App\Application\Organization\UpdateCompanyHandler;
use App\Infrastructure\Organization\CodeIgniterOrganizationAdministration;
use App\Infrastructure\Organization\CodeIgniterTenantAdministration;
use App\Infrastructure\Identity\CodeIgniterLoginAttemptLimiter;
use App\Infrastructure\AppShell\CodeIgniterAppShellReadModel;
use App\Infrastructure\Dashboard\MaintenanceCircuitDashboardOverview;
use App\Infrastructure\Dashboard\PreventiveDashboardDuePlans;
use App\Infrastructure\Dashboard\SystemDashboardClock;
use App\Infrastructure\Importations\CodeIgniterAssetImportGateway;
use App\Infrastructure\Importations\CodeIgniterImportReferenceGateway;
use App\Infrastructure\Importations\CodeIgniterImportRepository;
use App\Infrastructure\Importations\CodeIgniterImportUnitOfWork;
use App\Infrastructure\Importations\CodeIgniterMeasurementImportGateway;
use App\Infrastructure\Importations\CsvImportTemplateExporter;
use App\Infrastructure\Importations\LocalPrivateImportFileStorage;
use App\Infrastructure\Importations\NativeCsvSpreadsheetReader;
use App\Infrastructure\Importations\PhpSpreadsheetReader;
use App\Infrastructure\Assets\CodeIgniterBranchScope;
use App\Infrastructure\Assets\CodeIgniterAssetUnitOfWork;
use App\Infrastructure\Assets\CodeIgniterAssetCatalogReadModel;
use App\Infrastructure\Assets\CodeIgniterBrandRepository;
use App\Infrastructure\Assets\CodeIgniterEquipmentLifecycleRepository;
use App\Infrastructure\Assets\CodeIgniterEquipmentModelRepository;
use App\Infrastructure\Assets\CodeIgniterEquipmentReadModel;
use App\Infrastructure\Assets\CodeIgniterEquipmentRelationRepository;
use App\Infrastructure\Assets\CodeIgniterEquipmentRelationStatus;
use App\Infrastructure\Assets\CodeIgniterEquipmentRepository;
use App\Infrastructure\Assets\CodeIgniterEquipmentSearch;
use App\Infrastructure\Assets\CodeIgniterEquipmentTypeCatalog;
use App\Infrastructure\Assets\CodeIgniterEquipmentTypeChangeGuard;
use App\Infrastructure\Assets\CodeIgniterEquipmentWorkStatus;
use App\Infrastructure\Assets\EndroidEquipmentQrRenderer;
use App\Infrastructure\Assets\Attachment\CodeIgniterEquipmentAttachmentEquipmentScope;
use App\Infrastructure\Assets\Attachment\CodeIgniterEquipmentAttachmentReadModel;
use App\Infrastructure\Assets\Attachment\CodeIgniterEquipmentAttachmentRepository;
use App\Infrastructure\Assets\Attachment\FileinfoEquipmentAttachmentInspector;
use App\Infrastructure\Assets\Attachment\LocalPrivateAttachmentStorage;
use App\Infrastructure\Assets\Attachment\SystemEquipmentAttachmentClock;
use App\Infrastructure\Measurement\CodeIgniterReadingCorrectionRepository;
use App\Infrastructure\Measurement\CodeIgniterReadingHistory;
use App\Infrastructure\Measurement\CodeIgniterReadingRepository;
use App\Infrastructure\Measurement\CodeIgniterUnitOfWork;
use App\Infrastructure\MaintenanceCircuit\CodeIgniterCircuitOverview;
use App\Infrastructure\MaintenanceCircuit\CodeIgniterPreventiveOrderClosure;
use App\Infrastructure\MaintenanceCircuit\CodeIgniterPreventiveOrderFromNotice;
use App\Infrastructure\PreventiveMaintenance\CodeIgniterMaintenanceNoticeRepository;
use App\Infrastructure\PreventiveMaintenance\CodeIgniterPlanMantenimientoRepository;
use App\Infrastructure\PreventiveMaintenance\CodeIgniterPreventiveAssetGateway;
use App\Infrastructure\PreventiveMaintenance\CodeIgniterServiceTypeGateway;
use App\Infrastructure\PreventiveMaintenance\SystemClock;
use App\Infrastructure\WorkOrders\CodeIgniterWorkOrderRepository;
use App\Infrastructure\WorkOrders\CodeIgniterWorkOrderTransaction;
use App\Infrastructure\WorkOrders\SystemClock as WorkOrderClock;
use App\Infrastructure\Reports\CodeIgniterMaintenanceReportReadModel;
use App\Infrastructure\Reports\SystemReportClock;
use App\Domain\PreventiveMaintenance\EvaluadorVencimiento;
use App\Presentation\AppShellPayload;
use App\Presentation\AdministrationPayload;
use App\Presentation\OperationsPayload;
use CodeIgniter\Config\BaseService;

/**
 * Services Configuration file.
 *
 * Services are simply other classes/libraries that the system uses
 * to do its job. This is used by CodeIgniter to allow the core of the
 * framework to be swapped out easily without affecting the usage within
 * the rest of your application.
 *
 * This file holds any application-specific services, or service overrides
 * that you might need. An example has been included with the general
 * method format you should use for your service methods. For more examples,
 * see the core Services file at system/Config/Services.php.
 */
class Services extends BaseService
{
    public static function operationsPayload(bool $getShared = true): OperationsPayload
    {
        if ($getShared) {
            return static::getSharedInstance('operationsPayload');
        }

        return new OperationsPayload();
    }

    public static function administrationPayload(bool $getShared = true): AdministrationPayload
    {
        if ($getShared) {
            return static::getSharedInstance('administrationPayload');
        }

        return new AdministrationPayload();
    }

    public static function maintenanceReport(bool $getShared = true): GetMaintenanceReport
    {
        if ($getShared) {
            return static::getSharedInstance('maintenanceReport');
        }

        return new GetMaintenanceReport(
            new CodeIgniterMaintenanceReportReadModel(db_connect()),
            new SystemReportClock(),
        );
    }

    public static function appShellPayload(bool $getShared = true): AppShellPayload
    {
        if ($getShared) {
            return static::getSharedInstance('appShellPayload');
        }

        return new AppShellPayload(
            new GetAppShellContext(new CodeIgniterAppShellReadModel(db_connect())),
        );
    }

    public static function maintenanceDashboard(bool $getShared = true): GetMaintenanceDashboard
    {
        if ($getShared) {
            return static::getSharedInstance('maintenanceDashboard');
        }

        return new GetMaintenanceDashboard(
            new MaintenanceCircuitDashboardOverview(static::circuitOverview(false)),
            new PreventiveDashboardDuePlans(static::consultMaintenanceDue(false)),
            new SystemDashboardClock(),
        );
    }

    public static function createImportDraft(bool $getShared = true): CreateImportDraftHandler
    {
        if ($getShared) {
            return static::getSharedInstance('createImportDraft');
        }

        $database = db_connect();

        return new CreateImportDraftHandler(
            new PhpSpreadsheetReader(new NativeCsvSpreadsheetReader()),
            self::privateImportStorage(),
            new CodeIgniterImportRepository($database),
            new ImportRowValidator(new CodeIgniterImportReferenceGateway($database)),
        );
    }

    public static function confirmImport(bool $getShared = true): ConfirmImportHandler
    {
        if ($getShared) {
            return static::getSharedInstance('confirmImport');
        }

        $database = db_connect();

        return new ConfirmImportHandler(
            new CodeIgniterImportRepository($database),
            new CodeIgniterAssetImportGateway($database),
            new CodeIgniterMeasurementImportGateway($database),
            new CodeIgniterImportUnitOfWork($database),
            self::privateImportStorage(),
        );
    }

    public static function cancelImport(bool $getShared = true): CancelImportHandler
    {
        if ($getShared) {
            return static::getSharedInstance('cancelImport');
        }

        $database = db_connect();

        return new CancelImportHandler(
            new CodeIgniterImportRepository($database),
            new CodeIgniterImportUnitOfWork($database),
            self::privateImportStorage(),
        );
    }

    public static function listImports(bool $getShared = true): ListImportsHandler
    {
        if ($getShared) {
            return static::getSharedInstance('listImports');
        }

        return new ListImportsHandler(new CodeIgniterImportRepository(db_connect()));
    }

    public static function importPreview(bool $getShared = true): GetImportPreviewHandler
    {
        if ($getShared) {
            return static::getSharedInstance('importPreview');
        }

        return new GetImportPreviewHandler(new CodeIgniterImportRepository(db_connect()));
    }

    public static function importTemplate(bool $getShared = true): GenerateImportTemplateHandler
    {
        if ($getShared) {
            return static::getSharedInstance('importTemplate');
        }

        return new GenerateImportTemplateHandler(new CsvImportTemplateExporter());
    }

    public static function assetCatalog(bool $getShared = true): AssetCatalogService
    {
        if ($getShared) {
            return static::getSharedInstance('assetCatalog');
        }

        $database = db_connect();

        return new AssetCatalogService(
            new CodeIgniterBrandRepository($database),
            new CodeIgniterEquipmentModelRepository($database),
            new CodeIgniterEquipmentTypeCatalog($database),
            new CodeIgniterAssetCatalogReadModel($database),
            new CodeIgniterAssetUnitOfWork($database),
        );
    }

    public static function equipmentList(bool $getShared = true): ListEquipment
    {
        if ($getShared) {
            return static::getSharedInstance('equipmentList');
        }

        return new ListEquipment(new CodeIgniterEquipmentSearch(db_connect()));
    }

    public static function createEquipmentRelation(bool $getShared = true): CreateEquipmentRelationHandler
    {
        if ($getShared) {
            return static::getSharedInstance('createEquipmentRelation');
        }

        $database = db_connect();

        return new CreateEquipmentRelationHandler(
            new CodeIgniterEquipmentRelationRepository($database),
            new CodeIgniterAssetUnitOfWork($database),
        );
    }

    public static function finishEquipmentRelation(bool $getShared = true): FinishEquipmentRelationHandler
    {
        if ($getShared) {
            return static::getSharedInstance('finishEquipmentRelation');
        }

        $database = db_connect();

        return new FinishEquipmentRelationHandler(
            new CodeIgniterEquipmentRelationRepository($database),
            new CodeIgniterAssetUnitOfWork($database),
        );
    }

    public static function equipmentQr(bool $getShared = true): RenderEquipmentQr
    {
        if ($getShared) {
            return static::getSharedInstance('equipmentQr');
        }

        return new RenderEquipmentQr(
            new GetEquipmentQrPayload(new CodeIgniterEquipmentSearch(db_connect())),
            new EndroidEquipmentQrRenderer(),
        );
    }

    public static function uploadEquipmentAttachment(bool $getShared = true): UploadEquipmentAttachmentHandler
    {
        if ($getShared) {
            return static::getSharedInstance('uploadEquipmentAttachment');
        }

        $configuredRoot = trim((string) env('uploads.privatePath', ''));
        $maximumSizeMb = max(1, (int) env('uploads.maxSizeMB', 10));

        return new UploadEquipmentAttachmentHandler(
            new FileinfoEquipmentAttachmentInspector(),
            new LocalPrivateAttachmentStorage($configuredRoot === '' ? null : $configuredRoot),
            new CodeIgniterEquipmentAttachmentRepository(db_connect()),
            new CodeIgniterEquipmentAttachmentEquipmentScope(db_connect()),
            new SystemEquipmentAttachmentClock(),
            $maximumSizeMb * 1024 * 1024,
        );
    }

    public static function listEquipmentAttachments(bool $getShared = true): ListEquipmentAttachmentsHandler
    {
        if ($getShared) {
            return static::getSharedInstance('listEquipmentAttachments');
        }

        return new ListEquipmentAttachmentsHandler(new CodeIgniterEquipmentAttachmentReadModel(db_connect()));
    }

    public static function downloadEquipmentAttachment(bool $getShared = true): DownloadEquipmentAttachmentHandler
    {
        if ($getShared) {
            return static::getSharedInstance('downloadEquipmentAttachment');
        }

        $configuredRoot = trim((string) env('uploads.privatePath', ''));

        return new DownloadEquipmentAttachmentHandler(
            new CodeIgniterEquipmentAttachmentRepository(db_connect()),
            new LocalPrivateAttachmentStorage($configuredRoot === '' ? null : $configuredRoot),
        );
    }

    public static function retireEquipmentAttachment(bool $getShared = true): RetireEquipmentAttachmentHandler
    {
        if ($getShared) {
            return static::getSharedInstance('retireEquipmentAttachment');
        }

        return new RetireEquipmentAttachmentHandler(
            new CodeIgniterEquipmentAttachmentRepository(db_connect()),
            new SystemEquipmentAttachmentClock(),
        );
    }

    public static function equipmentDetails(bool $getShared = true): GetEquipmentDetails
    {
        if ($getShared) {
            return static::getSharedInstance('equipmentDetails');
        }

        return new GetEquipmentDetails(new CodeIgniterEquipmentReadModel(db_connect()));
    }

    public static function updateEquipment(bool $getShared = true): UpdateEquipmentHandler
    {
        if ($getShared) {
            return static::getSharedInstance('updateEquipment');
        }

        $database = db_connect();

        return new UpdateEquipmentHandler(
            new CodeIgniterEquipmentLifecycleRepository($database),
            new CodeIgniterAssetUnitOfWork($database),
            new CodeIgniterBrandRepository($database),
            new CodeIgniterEquipmentModelRepository($database),
            new CodeIgniterEquipmentTypeCatalog($database),
            new \App\Infrastructure\Assets\SystemAssetClock(),
            new CodeIgniterEquipmentTypeChangeGuard($database),
        );
    }

    public static function transferEquipment(bool $getShared = true): TransferEquipmentHandler
    {
        if ($getShared) {
            return static::getSharedInstance('transferEquipment');
        }

        $database = db_connect();

        return new TransferEquipmentHandler(
            new CodeIgniterEquipmentLifecycleRepository($database),
            new CodeIgniterBranchScope($database),
            new CodeIgniterAssetUnitOfWork($database),
        );
    }

    public static function decommissionEquipment(bool $getShared = true): DecommissionEquipmentHandler
    {
        if ($getShared) {
            return static::getSharedInstance('decommissionEquipment');
        }

        $database = db_connect();

        return new DecommissionEquipmentHandler(
            new CodeIgniterEquipmentLifecycleRepository($database),
            new CodeIgniterEquipmentWorkStatus($database),
            new CodeIgniterAssetUnitOfWork($database),
            new CodeIgniterEquipmentRelationStatus($database),
        );
    }

    public static function readingHistory(bool $getShared = true): ListReadingHistoryHandler
    {
        if ($getShared) {
            return static::getSharedInstance('readingHistory');
        }

        return new ListReadingHistoryHandler(new CodeIgniterReadingHistory(db_connect()));
    }

    public static function correctReading(bool $getShared = true): CorrectReadingHandler
    {
        if ($getShared) {
            return static::getSharedInstance('correctReading');
        }

        $database = db_connect();

        return new CorrectReadingHandler(
            new CodeIgniterEquipmentRepository($database),
            new CodeIgniterReadingRepository($database),
            new CodeIgniterReadingCorrectionRepository($database),
            new CodeIgniterUnitOfWork($database),
        );
    }

    public static function createEquipment(bool $getShared = true): CreateEquipmentHandler
    {
        if ($getShared) {
            return static::getSharedInstance('createEquipment');
        }

        $database = db_connect();

        return new CreateEquipmentHandler(
            new CodeIgniterEquipmentRepository($database),
            new CodeIgniterEquipmentTypeCatalog($database),
            new CodeIgniterBranchScope($database),
            new CodeIgniterBrandRepository($database),
            new CodeIgniterEquipmentModelRepository($database),
        );
    }

    public static function registerReading(bool $getShared = true): RegisterReadingHandler
    {
        if ($getShared) {
            return static::getSharedInstance('registerReading');
        }

        $database = db_connect();

        return new RegisterReadingHandler(
            new CodeIgniterEquipmentRepository($database),
            new CodeIgniterReadingRepository($database),
            new CodeIgniterUnitOfWork($database),
        );
    }

    public static function assignMaintenancePlan(bool $getShared = true): AsignarPlan
    {
        if ($getShared) {
            return static::getSharedInstance('assignMaintenancePlan');
        }

        $database = db_connect();

        return new AsignarPlan(
            new CodeIgniterPlanMantenimientoRepository($database),
            new CodeIgniterPreventiveAssetGateway($database),
            new CodeIgniterServiceTypeGateway($database),
            new SystemClock(),
        );
    }

    public static function consultMaintenanceDue(bool $getShared = true): ConsultarVencimientos
    {
        if ($getShared) {
            return static::getSharedInstance('consultMaintenanceDue');
        }

        $database = db_connect();

        return new ConsultarVencimientos(
            new CodeIgniterPlanMantenimientoRepository($database),
            new CodeIgniterPreventiveAssetGateway($database),
            new SystemClock(),
            new EvaluadorVencimiento(),
        );
    }

    public static function materializeOverdueNotice(bool $getShared = true): MaterializarAvisoVencido
    {
        if ($getShared) {
            return static::getSharedInstance('materializeOverdueNotice');
        }

        return new MaterializarAvisoVencido(new CodeIgniterMaintenanceNoticeRepository(db_connect()));
    }

    public static function recalculatePlanAfterClosure(bool $getShared = true): RecalcularPlanTrasCierre
    {
        if ($getShared) {
            return static::getSharedInstance('recalculatePlanAfterClosure');
        }

        return new RecalcularPlanTrasCierre(new CodeIgniterPlanMantenimientoRepository(db_connect()));
    }

    public static function detectOverduePlans(bool $getShared = true): DetectOverduePlans
    {
        if ($getShared) {
            return static::getSharedInstance('detectOverduePlans');
        }

        return new DetectOverduePlans(
            static::consultMaintenanceDue(false),
            static::materializeOverdueNotice(false),
            new SystemClock(),
        );
    }

    public static function circuitOverview(bool $getShared = true): GetCircuitOverview
    {
        if ($getShared) {
            return static::getSharedInstance('circuitOverview');
        }

        return new GetCircuitOverview(new CodeIgniterCircuitOverview(db_connect()));
    }

    public static function generatePreventiveOrderFromNotice(bool $getShared = true): GeneratePreventiveOrderFromNotice
    {
        if ($getShared) {
            return static::getSharedInstance('generatePreventiveOrderFromNotice');
        }

        return new GeneratePreventiveOrderFromNotice(new CodeIgniterPreventiveOrderFromNotice(db_connect()));
    }

    public static function startWorkOrder(bool $getShared = true): StartWorkOrder
    {
        if ($getShared) {
            return static::getSharedInstance('startWorkOrder');
        }

        $database = db_connect();

        return new StartWorkOrder(
            new CodeIgniterWorkOrderRepository($database),
            new CodeIgniterWorkOrderTransaction($database),
            new WorkOrderClock(),
        );
    }

    public static function closePreventiveOrder(bool $getShared = true): ClosePreventiveOrder
    {
        if ($getShared) {
            return static::getSharedInstance('closePreventiveOrder');
        }

        return new ClosePreventiveOrder(new CodeIgniterPreventiveOrderClosure(db_connect()));
    }

    public static function loginAttemptLimiter(bool $getShared = true): LoginAttemptLimiter
    {
        if ($getShared) {
            return static::getSharedInstance('loginAttemptLimiter');
        }

        return new CodeIgniterLoginAttemptLimiter(
            service('throttler'),
            (int) env('auth.maxLoginAttempts', 5),
            (int) env('auth.lockoutMinutes', 15) * 60,
        );
    }

    public static function organizationAdministration(bool $getShared = true): OrganizationAdministrationPort
    {
        if ($getShared) {
            return static::getSharedInstance('organizationAdministration');
        }

        return new CodeIgniterOrganizationAdministration(db_connect());
    }

    public static function tenantAdministrationPort(bool $getShared = true): TenantAdministrationPort
    {
        if ($getShared) {
            return static::getSharedInstance('tenantAdministrationPort');
        }

        return new CodeIgniterTenantAdministration(db_connect());
    }

    public static function tenantAdministration(bool $getShared = true): TenantAdministrationService
    {
        if ($getShared) {
            return static::getSharedInstance('tenantAdministration');
        }

        return new TenantAdministrationService(static::tenantAdministrationPort());
    }

    public static function organizationOverview(bool $getShared = true): GetOrganizationOverview
    {
        if ($getShared) {
            return static::getSharedInstance('organizationOverview');
        }

        return new GetOrganizationOverview(static::organizationAdministration());
    }

    public static function createCompany(bool $getShared = true): CreateCompanyHandler
    {
        if ($getShared) {
            return static::getSharedInstance('createCompany');
        }

        return new CreateCompanyHandler(static::organizationAdministration());
    }

    public static function createCompanyAdministrator(bool $getShared = true): CreateCompanyAdministratorHandler
    {
        if ($getShared) {
            return static::getSharedInstance('createCompanyAdministrator');
        }

        return new CreateCompanyAdministratorHandler(static::organizationAdministration());
    }

    public static function updateCompany(bool $getShared = true): UpdateCompanyHandler
    {
        if ($getShared) {
            return static::getSharedInstance('updateCompany');
        }

        return new UpdateCompanyHandler(static::organizationAdministration());
    }

    public static function assignUserCompany(bool $getShared = true): AssignUserCompanyHandler
    {
        if ($getShared) {
            return static::getSharedInstance('assignUserCompany');
        }

        return new AssignUserCompanyHandler(static::organizationAdministration());
    }

    public static function assignUserRoles(bool $getShared = true): AssignUserRolesHandler
    {
        if ($getShared) {
            return static::getSharedInstance('assignUserRoles');
        }

        return new AssignUserRolesHandler(static::organizationAdministration());
    }

    private static function privateImportStorage(): LocalPrivateImportFileStorage
    {
        $configuredRoot = trim((string) env('imports.privatePath', ''));
        if ($configuredRoot === '') {
            $projectRoot = rtrim((string) ROOTPATH, '\\/');
            $configuredRoot = dirname($projectRoot)
                . DIRECTORY_SEPARATOR . basename($projectRoot) . '-private'
                . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'importaciones';
        }
        $maximumSizeMb = max(1, (int) env('imports.maxSizeMB', 10));

        return new LocalPrivateImportFileStorage($configuredRoot, $maximumSizeMb * 1024 * 1024);
    }

    /*
     * public static function example($getShared = true)
     * {
     *     if ($getShared) {
     *         return static::getSharedInstance('example');
     *     }
     *
     *     return new \CodeIgniter\Example();
     * }
     */
}
