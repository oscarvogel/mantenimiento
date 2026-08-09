<?php

declare(strict_types=1);

namespace App\Application\Importations;

use App\Domain\Importations\ImportRowStatus;

final class StagedImportRow
{
    /**
     * @param array<string, string|null>         $source
     * @param array<string, int|string|null>     $normalized
     * @param list<ImportRowIssue>               $issues
     */
    public function __construct(
        public readonly int $rowNumber,
        public readonly ImportRowStatus $status,
        public readonly array $source,
        public readonly array $normalized,
        public readonly array $issues,
    ) {
    }
}
