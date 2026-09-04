<?php

declare(strict_types=1);

namespace App\Application\PublicEquipmentAccess\Port;

interface PublicEquipmentTokenRepository
{
    public function activeTokenHashForEquipment(int $companyId, int $equipmentId): ?string;

    public function replaceActiveToken(
        int $companyId,
        int $equipmentId,
        string $tokenHash,
        ?int $actorUserId,
        string $occurredAt,
    ): bool;

    /** @return array{id:int,empresa_id:int,equipo_id:int,codigo:string,patente:?string,estado:string}|null */
    public function resolveActiveToken(string $tokenHash): ?array;
}
