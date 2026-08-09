<?php

declare(strict_types=1);

namespace App\Application\Assets;

final readonly class EquipmentQrPayload
{
    public function __construct(public int $equipmentId, public string $code, public string $relativePath) {}

    public function canonicalReference(): string
    {
        return 'mantenimiento:equipo:' . $this->equipmentId;
    }
}
