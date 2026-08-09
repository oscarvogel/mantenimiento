<?php

declare(strict_types=1);

namespace App\Application\Measurement;

final readonly class ListReadingHistoryQuery
{
    public function __construct(
        public int $equipmentId,
        public int $page = 1,
        public int $perPage = 20,
    ) {
    }
}
