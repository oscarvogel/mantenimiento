<?php

declare(strict_types=1);

namespace App\Application\Importations;

final class CreateImportDraftCommand
{
    public function __construct(
        public readonly string $type,
        public readonly string $uploadedPath,
        public readonly string $originalName,
        public readonly string $origin = 'CARGA_WEB',
    ) {
    }
}
