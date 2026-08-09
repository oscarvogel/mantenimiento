<?php

declare(strict_types=1);

namespace App\Application\PreventiveMaintenance\Port;

interface ServiceTypeGateway
{
    public function isActive(int $serviceTypeId): bool;
}
