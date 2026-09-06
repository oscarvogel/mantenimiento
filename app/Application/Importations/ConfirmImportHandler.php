<?php

declare(strict_types=1);

namespace App\Application\Importations;

use App\Application\Identity\ActorContext;
use App\Application\Importations\Port\AssetImportGateway;
use App\Application\Importations\Port\ExpirationImportGateway;
use App\Application\Importations\Port\ImportRepository;
use App\Application\Importations\Port\ImportUnitOfWork;
use App\Application\Importations\Port\MeasurementImportGateway;
use App\Application\Importations\Port\PrivateImportFileStorage;
use App\Domain\Importations\ImportBatch;
use App\Domain\Importations\ImportType;
use DomainException;

final class ConfirmImportHandler
{
    public function __construct(
        private readonly ImportRepository $imports,
        private readonly AssetImportGateway $assets,
        private readonly MeasurementImportGateway $measurements,
        private readonly ImportUnitOfWork $unitOfWork,
        private readonly PrivateImportFileStorage $files,
        private readonly int $batchSize = 200,
        private readonly ?ExpirationImportGateway $expirations = null,
    ) {
    }

    public function execute(ActorContext $actor, int $importId): ConfirmImportResult
    {
        $companyId = $this->tenantCompany($actor, 'importaciones.cargar');
        $privatePath = '';
        $result = $this->unitOfWork->transactional(function () use ($actor, $companyId, $importId, &$privatePath): ConfirmImportResult {
            $draft = $this->imports->findForUpdate(
                $importId, $companyId, $actor->userId(), $actor->hasAllCompanyBranches(),
            );
            if ($draft === null) {
                throw new DomainException('La importacion no existe o pertenece a otra empresa.');
            }
            $batch = new ImportBatch($draft->id, $draft->companyId, $draft->type, $draft->status);
            $batch->confirm();
            $privatePath = $draft->privatePath;

            $imported = $errors = $duplicates = 0;
            do {
                // Processed rows leave the VALIDA set, so every controlled batch starts at zero.
                $rows = $this->imports->pendingRows($draft->id, 0, $this->batchSize);
                foreach ($rows as $row) {
                    $data = $row['datos_normalizados'];
                    $branchId = (int) ($data['branch_id'] ?? 0);
                    if ($branchId <= 0 || ! $actor->canAccessBranch($companyId, $branchId)) {
                        $this->imports->markRowError($row['id'], 'La sucursal ya no esta autorizada para el actor.');
                        $errors++;
                        continue;
                    }
                    try {
                        if ($draft->type === ImportType::EQUIPOS || $draft->type === ImportType::UNIDADES_TRANSPORTE) {
                            $payload = $this->assetData($data, $companyId, $actor->userId(), $draft->id);
                            if ($this->assets->isDuplicate($companyId, $payload->code)) {
                                $this->imports->markRowDuplicate($row['id'], 'Equipo duplicado al momento de confirmar.');
                                $duplicates++;
                                continue;
                            }
                            $destinationId = $this->assets->import($payload);
                        } elseif ($draft->type === ImportType::LECTURAS) {
                            $payload = $this->measurementData($data, $companyId, $actor->userId(), $draft->id);
                            if ($this->measurements->isDuplicate($payload)) {
                                $this->imports->markRowDuplicate($row['id'], 'Lectura duplicada al momento de confirmar.');
                                $duplicates++;
                                continue;
                            }
                            $destinationId = $this->measurements->import($payload);
                        } elseif ($draft->type === ImportType::VENCIMIENTOS) {
                            if ($this->expirations === null) {
                                throw new DomainException('La importación de vencimientos no está disponible en este entorno.');
                            }
                            $payload = $this->expirationData($data, $companyId, $actor->userId(), $draft->id);
                            if ($this->expirations->isDuplicate($payload)) {
                                $this->imports->markRowDuplicate($row['id'], 'Vencimiento duplicado al momento de confirmar.');
                                $duplicates++;
                                continue;
                            }
                            $destinationId = $this->expirations->import($payload);
                        } else {
                            throw new DomainException('El tipo de importación no tiene un destino configurado.');
                        }
                        $this->imports->markRowImported($row['id'], $destinationId);
                        $imported++;
                    } catch (DomainException $exception) {
                        $this->imports->markRowError($row['id'], $exception->getMessage());
                        $errors++;
                    }
                }
            } while (count($rows) === $this->batchSize);

            $summary = "Confirmada: {$imported} importadas, {$errors} con error y {$duplicates} duplicadas omitidas.";
            $this->imports->markConfirmed($draft->id, $actor->userId(), $imported, $errors, $duplicates, $summary);

            return new ConfirmImportResult($draft->id, $imported, $errors, $duplicates);
        });

        if ($privatePath !== '') {
            $this->files->delete($privatePath);
        }
        return $result;
    }

    /** @param array<string,mixed> $data */
    private function assetData(array $data, int $companyId, int $actorId, int $importId): AssetImportData
    {
        return new AssetImportData(
            $companyId, (int) $data['branch_id'], (int) $data['equipment_type_id'], (string) $data['code'],
            $data['plate'] ?? null, isset($data['brand_id']) ? (int) $data['brand_id'] : null,
            isset($data['model_id']) ? (int) $data['model_id'] : null,
            isset($data['year']) ? (int) $data['year'] : null, $data['chassis'] ?? null,
            $data['engine'] ?? null, (string) $data['registered_at'], $data['notes'] ?? null,
            $actorId, $importId,
        );
    }

    /** @param array<string,mixed> $data */
    private function measurementData(array $data, int $companyId, int $actorId, int $importId): MeasurementImportData
    {
        return new MeasurementImportData(
            $companyId, (int) $data['branch_id'], (int) $data['equipment_id'], (string) $data['recorded_at'],
            isset($data['kilometers']) ? (int) $data['kilometers'] : null, $data['hours'] ?? null,
            (string) $data['origin'], (string) $data['source_origin'], $data['notes'] ?? null, $actorId, $importId,
        );
    }

    /** @param array<string,mixed> $data */
    private function expirationData(array $data, int $companyId, int $actorId, int $importId): ExpirationImportData
    {
        return new ExpirationImportData(
            $companyId,
            (int) $data['branch_id'],
            (int) $data['equipment_id'],
            (string) $data['expiration_type'],
            (string) $data['expiration_date'],
            $data['issue_date'] ?? null,
            $data['document_number'] ?? null,
            $data['notes'] ?? null,
            $actorId,
            $importId,
        );
    }

    private function tenantCompany(ActorContext $actor, string $permission): int
    {
        if ($actor->isSuperAdmin() || $actor->companyId() === null || ! $actor->hasPermission($permission)) {
            throw new DomainException('No tenes permiso para confirmar importaciones de una empresa.');
        }
        return $actor->companyId();
    }
}
