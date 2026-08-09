<?php

declare(strict_types=1);

use App\Application\Identity\ActorContext;
use App\Application\Importations\AssetImportData;
use App\Application\Importations\CancelImportHandler;
use App\Application\Importations\ConfirmImportHandler;
use App\Application\Importations\CreateImportDraftCommand;
use App\Application\Importations\CreateImportDraftHandler;
use App\Application\Importations\ImportDraft;
use App\Application\Importations\ImportHistoryPage;
use App\Application\Importations\ImportPreview;
use App\Application\Importations\ImportRowValidator;
use App\Application\Importations\MeasurementImportData;
use App\Application\Importations\Port\AssetImportGateway;
use App\Application\Importations\Port\ImportReferenceGateway;
use App\Application\Importations\Port\ImportRepository;
use App\Application\Importations\Port\ImportUnitOfWork;
use App\Application\Importations\Port\MeasurementImportGateway;
use App\Application\Importations\Port\PrivateImportFileStorage;
use App\Application\Importations\Port\SpreadsheetReader;
use App\Application\Importations\SpreadsheetData;
use App\Application\Importations\StoredImportFile;
use App\Domain\Importations\ImportStatus;
use App\Domain\Importations\ImportType;
use PHPUnit\Framework\TestCase;

final class ImportWorkflowHandlersTest extends TestCase
{
    public function testCreateDraftStagesPreviewWithoutWritingDestination(): void
    {
        $repository = new WorkflowImportRepositoryFake();
        $references = new WorkflowReferenceGatewayFake();
        $references->duplicateCodes = ['CAM-2'];
        $reader = new WorkflowSpreadsheetReaderFake(new SpreadsheetData(
            ImportType::EQUIPOS->templateHeaders(),
            [
                ['sucursal_codigo' => 'CENTRAL', 'tipo_equipo' => 'Camion', 'codigo' => 'CAM-1', 'patente' => '', 'marca' => '', 'modelo' => '', 'anio' => '', 'chasis' => '', 'motor' => '', 'fecha_alta' => '2026-08-01', 'observaciones' => ''],
                ['sucursal_codigo' => 'CENTRAL', 'tipo_equipo' => 'Camion', 'codigo' => 'CAM-2', 'patente' => '', 'marca' => '', 'modelo' => '', 'anio' => '', 'chasis' => '', 'motor' => '', 'fecha_alta' => '2026-08-01', 'observaciones' => ''],
            ],
        ));
        $handler = new CreateImportDraftHandler(
            $reader,
            new WorkflowFileStorageFake(),
            $repository,
            new ImportRowValidator($references),
        );

        $result = $handler->execute($this->actor([7]), new CreateImportDraftCommand('equipos', 'upload.tmp', 'equipos.csv'));

        self::assertSame(2, $result->totalRows);
        self::assertSame(1, $result->validRows);
        self::assertSame(1, $result->duplicateRows);
        self::assertSame(ImportStatus::BORRADOR_VALIDADO, $repository->draft?->status);
        self::assertCount(2, $repository->rows);
    }

    public function testConfirmRechecksDuplicateAndImportsOnlyCurrentValidRows(): void
    {
        $repository = new WorkflowImportRepositoryFake();
        $repository->draft = new ImportDraft(40, 5, ImportType::EQUIPOS, ImportStatus::BORRADOR_VALIDADO, '/private/import.csv');
        $repository->pending = [
            ['id' => 1, 'numero_fila' => 2, 'estado' => 'VALIDA', 'datos_normalizados' => [
                'branch_id' => 7, 'equipment_type_id' => 3, 'code' => 'CAM-1', 'plate' => null,
                'brand_id' => null, 'model_id' => null, 'year' => null, 'chassis' => null,
                'engine' => null, 'registered_at' => '2026-08-01', 'notes' => null,
            ]],
            ['id' => 2, 'numero_fila' => 3, 'estado' => 'VALIDA', 'datos_normalizados' => [
                'branch_id' => 7, 'equipment_type_id' => 3, 'code' => 'CAM-2', 'plate' => null,
                'brand_id' => null, 'model_id' => null, 'year' => null, 'chassis' => null,
                'engine' => null, 'registered_at' => '2026-08-01', 'notes' => null,
            ]],
        ];
        $assets = new WorkflowAssetGatewayFake();
        $assets->duplicates = ['CAM-2'];
        $files = new WorkflowFileStorageFake();
        $handler = new ConfirmImportHandler(
            $repository, $assets, new WorkflowMeasurementGatewayFake(), new WorkflowUnitOfWorkFake(), $files,
        );

        $result = $handler->execute($this->actor([7]), 40);

        self::assertSame(1, $result->importedRows);
        self::assertSame(1, $result->duplicateRows);
        self::assertSame(['CAM-1'], $assets->importedCodes);
        self::assertSame([1 => 'IMPORTADA', 2 => 'DUPLICADA'], $repository->finished);
        self::assertSame(['/private/import.csv'], $files->deleted);
    }

    public function testCancelMarksDraftAndCleansPrivateFileWithoutDestinationWrites(): void
    {
        $repository = new WorkflowImportRepositoryFake();
        $repository->draft = new ImportDraft(41, 5, ImportType::LECTURAS, ImportStatus::BORRADOR_VALIDADO, '/private/readings.xlsx');
        $files = new WorkflowFileStorageFake();
        (new CancelImportHandler($repository, new WorkflowUnitOfWorkFake(), $files))->execute($this->actor([7]), 41);

        self::assertTrue($repository->cancelled);
        self::assertSame(['/private/readings.xlsx'], $files->deleted);
    }

    public function testAnotherRestrictedUserCannotConfirmSomeoneElsesDraft(): void
    {
        $repository = new WorkflowImportRepositoryFake();
        $repository->draft = new ImportDraft(42, 5, ImportType::EQUIPOS, ImportStatus::BORRADOR_VALIDADO, '/private/other.csv');
        $handler = new ConfirmImportHandler(
            $repository, new WorkflowAssetGatewayFake(), new WorkflowMeasurementGatewayFake(),
            new WorkflowUnitOfWorkFake(), new WorkflowFileStorageFake(),
        );

        $this->expectException(DomainException::class);
        $handler->execute(new ActorContext(10, 5, false, false, ['Responsable'], ['importaciones.cargar'], [7]), 42);
    }

    /** @param list<int> $branches */
    private function actor(array $branches): ActorContext
    {
        return new ActorContext(9, 5, false, false, ['Responsable'], ['importaciones.ver', 'importaciones.cargar'], $branches);
    }
}

final class WorkflowSpreadsheetReaderFake implements SpreadsheetReader
{
    public function __construct(private readonly SpreadsheetData $data) {}
    public function read(string $privatePath, int $maximumRows = 5000): SpreadsheetData { return $this->data; }
}

final class WorkflowFileStorageFake implements PrivateImportFileStorage
{
    /** @var list<string> */ public array $deleted = [];
    public function store(string $uploadedPath, string $originalName): StoredImportFile { return new StoredImportFile('/private/import.csv', $originalName, 'text/csv', 20, str_repeat('a', 64)); }
    public function delete(string $privatePath): void { $this->deleted[] = $privatePath; }
    public function purgeOlderThan(int $retentionDays, array $protectedPaths = []): int { return 0; }
}

final class WorkflowImportRepositoryFake implements ImportRepository
{
    public int $ownerUserId = 9;
    public ?ImportDraft $draft = null;
    /** @var list<object> */ public array $rows = [];
    /** @var list<array{id:int,numero_fila:int,estado:string,datos_normalizados:array<string,mixed>}> */ public array $pending = [];
    /** @var array<int,string> */ public array $finished = [];
    public bool $cancelled = false;
    public function create(int $companyId, ImportType $type, string $originalName, string $privatePath, string $mediaType, string $sha256, string $origin, int $actorUserId): int { $this->draft = new ImportDraft(40, $companyId, $type, ImportStatus::FALLIDO, $privatePath); return 40; }
    public function stageRows(int $importId, array $rows): void { array_push($this->rows, ...$rows); }
    public function markValidated(int $importId, int $total, int $valid, int $errors, int $duplicates, string $summary): void { $this->draft = new ImportDraft($importId, 5, $this->draft?->type ?? ImportType::EQUIPOS, ImportStatus::BORRADOR_VALIDADO, $this->draft?->privatePath ?? ''); }
    public function markFailed(int $importId, string $summary): void {}
    public function findForUpdate(int $importId, int $companyId, int $actorUserId, bool $allBranches): ?ImportDraft { return $this->draft?->id === $importId && $this->draft->companyId === $companyId && ($allBranches || $actorUserId === $this->ownerUserId) ? $this->draft : null; }
    public function pendingRows(int $importId, int $offset, int $limit): array { $rows = array_values(array_filter($this->pending, fn (array $row): bool => ! isset($this->finished[$row['id']]))); return array_slice($rows, $offset, $limit); }
    public function markRowImported(int $rowId, int $destinationId): void { $this->finished[$rowId] = 'IMPORTADA'; }
    public function markRowDuplicate(int $rowId, string $message): void { $this->finished[$rowId] = 'DUPLICADA'; }
    public function markRowError(int $rowId, string $message): void { $this->finished[$rowId] = 'ERROR'; }
    public function markConfirmed(int $importId, int $actorUserId, int $imported, int $errors, int $duplicates, string $summary): void { $this->draft = new ImportDraft($importId, 5, $this->draft?->type ?? ImportType::EQUIPOS, ImportStatus::CONFIRMADO, ''); }
    public function markCancelled(int $importId, int $actorUserId, string $summary): void { $this->cancelled = true; }
    public function history(int $companyId, int $actorUserId, array $branchIds, bool $allBranches, int $page, int $perPage): ImportHistoryPage { return new ImportHistoryPage([], $page, $perPage, 0); }
    public function preview(int $importId, int $companyId, int $actorUserId, array $branchIds, bool $allBranches, int $page, int $perPage): ?ImportPreview { return null; }
}

final class WorkflowReferenceGatewayFake implements ImportReferenceGateway
{
    /** @var list<string> */ public array $duplicateCodes = [];
    public function activeBranchByCode(int $companyId, string $code): ?array { return ['id' => 7, 'codigo' => 'CENTRAL']; }
    public function activeEquipmentTypeByName(string $name): ?array { return ['id' => 3, 'nombre' => 'Camion', 'controla_km' => true, 'controla_horas' => true]; }
    public function activeBrandByName(int $companyId, string $name): ?array { return null; }
    public function activeModelByName(int $companyId, int $brandId, int $typeId, string $name): ?array { return null; }
    public function activeEquipmentByCode(int $companyId, string $code): ?array { return null; }
    public function equipmentCodeExists(int $companyId, string $code): bool { return in_array($code, $this->duplicateCodes, true); }
    public function equipmentPlateExists(int $companyId, string $plate): bool { return false; }
    public function readingDuplicateExists(int $companyId, int $equipmentId, string $recordedAt, ?int $kilometers, ?string $hours, string $origin): bool { return false; }
}

final class WorkflowAssetGatewayFake implements AssetImportGateway
{
    /** @var list<string> */ public array $duplicates = [];
    /** @var list<string> */ public array $importedCodes = [];
    public function isDuplicate(int $companyId, string $code): bool { return in_array($code, $this->duplicates, true); }
    public function import(AssetImportData $data): int { $this->importedCodes[] = $data->code; return 100 + count($this->importedCodes); }
}

final class WorkflowMeasurementGatewayFake implements MeasurementImportGateway
{
    public function isDuplicate(MeasurementImportData $data): bool { return false; }
    public function import(MeasurementImportData $data): int { return 200; }
}

final class WorkflowUnitOfWorkFake implements ImportUnitOfWork
{
    public function transactional(callable $operation): mixed { return $operation(); }
}
