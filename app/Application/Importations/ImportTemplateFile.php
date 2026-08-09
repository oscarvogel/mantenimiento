<?php

declare(strict_types=1);

namespace App\Application\Importations;

final class ImportTemplateFile
{
    public function __construct(
        public readonly string $fileName,
        public readonly string $mediaType,
        public readonly string $contents,
    ) {
    }
}
