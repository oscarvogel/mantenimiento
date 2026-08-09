<?php

declare(strict_types=1);

namespace App\Application\Importations;

use App\Domain\Importations\ImportStatus;
use App\Domain\Importations\ImportType;

final class ImportDraft
{
    public function __construct(
        public readonly int $id,
        public readonly int $companyId,
        public readonly ImportType $type,
        public readonly ImportStatus $status,
        public readonly string $privatePath,
    ) {
    }
}
