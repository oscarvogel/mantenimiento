<?php

declare(strict_types=1);

namespace App\Application\Dashboard\Port;

interface GlobalDashboardReadModel
{
    /** @return array<string,mixed> */
    public function fetch(?int $companyId = null): array;
}
