<?php

declare(strict_types=1);

namespace App\Application\PreventiveMaintenance\Port;

interface ServiceTypeGateway
{
    /**
     * @return array{
     *     id:int,
     *     intervalKm:?int,
     *     intervalHoursTenths:?int,
     *     intervalDays:?int,
     *     warningKm:?int,
     *     warningHoursTenths:?int,
     *     warningDays:?int,
     *     priority:string
     * }|null
     */
    public function findActiveDefinition(int $companyId, int $serviceTypeId): ?array;
}
