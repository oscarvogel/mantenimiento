<?php

declare(strict_types=1);

namespace App\Application\Importations;

final class ConfirmImportResult
{
    public function __construct(
        public readonly int $importId,
        public readonly int $importedRows,
        public readonly int $errorRows,
        public readonly int $duplicateRows,
    ) {
    }
}
