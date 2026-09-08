<?php

declare(strict_types=1);

namespace App\Application\Importations;

use App\Domain\Expirations\ExpirationSubjectType;

final class ExpirationImportData
{
    public function __construct(
        public readonly int $companyId,
        public readonly ExpirationSubjectType $subjectType,
        public readonly int $subjectId,
        public readonly ?int $branchId,
        public readonly string $type,
        public readonly string $expirationDate,
        public readonly ?string $issueDate,
        public readonly ?string $documentNumber,
        public readonly ?string $notes,
        public readonly int $actorUserId,
        public readonly int $importId,
    ) {
    }
}
