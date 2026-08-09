<?php

declare(strict_types=1);

namespace App\Application\Importations;

final class StoredImportFile
{
    public function __construct(
        public readonly string $path,
        public readonly string $originalName,
        public readonly string $mediaType,
        public readonly int $size,
        public readonly string $sha256,
    ) {
    }
}
