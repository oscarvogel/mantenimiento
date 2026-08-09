<?php

declare(strict_types=1);

namespace App\Application\Importations;

final class CreateImportDraftResult
{
    public function __construct(
        public readonly int $importId,
        public readonly int $totalRows,
        public readonly int $validRows,
        public readonly int $errorRows,
        public readonly int $duplicateRows,
    ) {
    }
}
