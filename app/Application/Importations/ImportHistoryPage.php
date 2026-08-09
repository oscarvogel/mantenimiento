<?php

declare(strict_types=1);

namespace App\Application\Importations;

final class ImportHistoryPage
{
    /** @param list<array<string, mixed>> $items */
    public function __construct(
        public readonly array $items,
        public readonly int $page,
        public readonly int $perPage,
        public readonly int $total,
    ) {
    }
}
