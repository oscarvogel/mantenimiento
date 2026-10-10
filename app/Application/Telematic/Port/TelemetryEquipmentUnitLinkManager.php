<?php

declare(strict_types=1);

namespace App\Application\Telematic\Port;

interface TelemetryEquipmentUnitLinkManager
{
    /**
     * @return array{
     *   integration:array{id:int,name:string,provider:string},
     *   equipment:list<array{id:int,code:string,plate:?string}>,
     *   units:list<array{id:string,name:string}>,
     *   links:array<int,string>
     * }
     */
    public function snapshotFor(int $companyId, int $integrationId): array;

    /** @param array<int,string> $assignments equipo_id => unidad_externa | __unlink__ */
    public function saveFor(int $companyId, int $userId, int $integrationId, array $assignments): int;
}
