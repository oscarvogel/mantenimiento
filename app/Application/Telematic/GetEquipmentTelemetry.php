<?php

declare(strict_types=1);

namespace App\Application\Telematic;

use App\Application\Telematic\Port\TelemetrySnapshotReader;

/**
 * Instantáneas de telemetría de un equipo, para la ficha.
 *
 * Existe como caso de uso por una razón concreta: un superadmin no tiene
 * empresa, y esa regla —"sin empresa no hay telemetría"— tiene que estar
 * probada. Si vive dentro del adaptador, con la base de datos de por medio,
 * nadie la puede testear y un `null` revienta la ficha entera con un error
 * genérico que no dice nada.
 *
 * Aquí se resuelve antes de tocar la base, y es trivial de probar con un
 * lector falso.
 */
final readonly class GetEquipmentTelemetry
{
    public function __construct(private TelemetrySnapshotReader $reader)
    {
    }

    /** @return list<array<string,mixed>> */
    public function execute(?int $companyId, int $equipmentId): array
    {
        if ($companyId === null || $companyId <= 0 || $equipmentId <= 0) {
            return [];
        }

        return $this->reader->forEquipment($companyId, $equipmentId);
    }
}