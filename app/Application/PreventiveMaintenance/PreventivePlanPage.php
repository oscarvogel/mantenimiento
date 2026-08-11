<?php

declare(strict_types=1);

namespace App\Application\PreventiveMaintenance;

final readonly class PreventivePlanPage
{
    /**
     * @param list<array<string,mixed>> $items
     * @param list<array<string,mixed>> $equipment
     * @param list<array<string,mixed>> $serviceTypes
     * @param list<array<string,mixed>> $branches
     * @param list<array<string,mixed>> $templateDefaults
     */
    public function __construct(
        public array $items,
        public int $page,
        public int $perPage,
        public int $total,
        public array $equipment,
        public array $serviceTypes,
        public array $branches,
        public array $templateDefaults,
    ) {
    }

    public function totalPages(): int
    {
        return max(1, (int) ceil($this->total / $this->perPage));
    }
}
