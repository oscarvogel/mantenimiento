<?php

declare(strict_types=1);

namespace App\Application\Importations\Port;

use App\Application\Importations\ImportDraft;
use App\Application\Importations\ImportHistoryPage;
use App\Application\Importations\ImportPreview;
use App\Application\Importations\StagedImportRow;
use App\Domain\Importations\ImportStatus;
use App\Domain\Importations\ImportType;

interface ImportRepository
{
    public function create(
        int $companyId,
        ImportType $type,
        string $originalName,
        string $privatePath,
        string $mediaType,
        string $sha256,
        string $origin,
        int $actorUserId,
    ): int;

    /** @param list<StagedImportRow> $rows */
    public function stageRows(int $importId, array $rows): void;

    public function markValidated(int $importId, int $total, int $valid, int $errors, int $duplicates, string $summary): void;

    public function markFailed(int $importId, string $summary): void;

    public function findForUpdate(int $importId, int $companyId, int $actorUserId, bool $allBranches): ?ImportDraft;

    /** @return list<array{id:int,numero_fila:int,estado:string,datos_normalizados:array<string,mixed>}> */
    public function pendingRows(int $importId, int $offset, int $limit): array;

    public function markRowImported(int $rowId, int $destinationId): void;

    public function markRowDuplicate(int $rowId, string $message): void;

    public function markRowError(int $rowId, string $message): void;

    public function markConfirmed(int $importId, int $actorUserId, int $imported, int $errors, int $duplicates, string $summary): void;

    public function markCancelled(int $importId, int $actorUserId, string $summary): void;

    public function history(int $companyId, int $actorUserId, array $branchIds, bool $allBranches, int $page, int $perPage): ImportHistoryPage;

    public function preview(int $importId, int $companyId, int $actorUserId, array $branchIds, bool $allBranches, int $page, int $perPage): ?ImportPreview;
}
