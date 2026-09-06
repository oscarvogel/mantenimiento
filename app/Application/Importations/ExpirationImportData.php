<?php

declare(strict_types=1);

namespace App\Application\Importations;

/**
 * Datos de un vencimiento ya traducidos por el Anti-Corruption Layer de
 * importaciones. El contexto de Vencimientos decide cómo persistirlos.
 */
final class ExpirationImportData
{
    public function __construct(
        public readonly int $companyId,
        public readonly int $branchId,
        public readonly int $equipmentId,
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
