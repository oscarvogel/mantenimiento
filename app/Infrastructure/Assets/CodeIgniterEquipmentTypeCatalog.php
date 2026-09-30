<?php

declare(strict_types=1);

namespace App\Infrastructure\Assets;

use App\Application\Assets\Port\EquipmentTypeCatalog;
use App\Domain\Assets\EquipmentType;
use CodeIgniter\Database\BaseConnection;

final class CodeIgniterEquipmentTypeCatalog implements EquipmentTypeCatalog
{
    public function __construct(private readonly BaseConnection $database)
    {
    }

    public function updateTracking(int $typeId, bool $tracksKilometers, bool $tracksHours): void
    {
        $exists = $this->database->table('tipos_equipo')
            ->where('id', $typeId)
            ->where('activo', 1)
            ->countAllResults() === 1;

        if (! $exists) {
            throw new \DomainException('El tipo de equipo no existe o está inactivo.');
        }

        $this->database->table('tipos_equipo')
            ->where('id', $typeId)
            ->update([
                'controla_km' => $tracksKilometers ? 1 : 0,
                'controla_horas' => $tracksHours ? 1 : 0,
            ]);
    }

    public function findActiveById(int $typeId): ?EquipmentType
    {
        $row = $this->database->table('tipos_equipo')
            ->select('id, nombre, controla_km, controla_horas')
            ->where('id', $typeId)
            ->where('activo', 1)
            ->get()->getRowArray();

        return $row === null ? null : new EquipmentType(
            (int) $row['id'],
            (string) $row['nombre'],
            (bool) $row['controla_km'],
            (bool) $row['controla_horas'],
        );
    }
}
