<?php

declare(strict_types=1);

namespace App\Application\Importations;

final class ImportPreview
{
    /** @param array<string, mixed> $header @param list<array<string, mixed>> $rows */
    public function __construct(
        public readonly array $header,
        public readonly array $rows,
        public readonly int $page,
        public readonly int $perPage,
        public readonly int $total,
    ) {
    }
}
