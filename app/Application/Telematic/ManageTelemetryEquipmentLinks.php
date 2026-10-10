<?php

declare(strict_types=1);

namespace App\Application\Telematic;

use App\Application\Telematic\Port\TelemetryEquipmentUnitLinkManager;
use DomainException;

final class ManageTelemetryEquipmentLinks
{
    public function __construct(private readonly TelemetryEquipmentUnitLinkManager $manager)
    {
    }

    /** @return array<string,mixed> */
    public function snapshot(int $companyId, int $integrationId): array
    {
        $this->assertScope($companyId, $integrationId);

        return $this->manager->snapshotFor($companyId, $integrationId);
    }

    /** @param array<array-key,mixed> $assignments */
    public function save(int $companyId, int $userId, int $integrationId, array $assignments): int
    {
        $this->assertScope($companyId, $integrationId, $userId);

        $normalized = [];
        $selectedUnits = [];
        foreach ($assignments as $equipmentId => $externalUnitId) {
            if (! is_string($equipmentId) && ! is_int($equipmentId)) {
                throw new DomainException('La selección contiene un equipo inválido.');
            }
            $equipmentId = (string) $equipmentId;
            if (! ctype_digit($equipmentId) || (int) $equipmentId <= 0) {
                throw new DomainException('La selección contiene un equipo inválido.');
            }
            if (! is_string($externalUnitId)) {
                throw new DomainException('La selección contiene una unidad inválida.');
            }

            $externalUnitId = trim($externalUnitId);
            if ($externalUnitId === '') {
                continue;
            }
            if ($externalUnitId !== '__unlink__' && mb_strlen($externalUnitId) > 100) {
                throw new DomainException('La unidad seleccionada no es válida.');
            }
            if ($externalUnitId !== '__unlink__' && isset($selectedUnits[$externalUnitId])) {
                throw new DomainException('Cada unidad de Wialon se puede vincular a un solo equipo.');
            }

            if ($externalUnitId !== '__unlink__') {
                $selectedUnits[$externalUnitId] = true;
            }
            $normalized[(int) $equipmentId] = $externalUnitId;
        }

        return $this->manager->saveFor($companyId, $userId, $integrationId, $normalized);
    }

    private function assertScope(int $companyId, int $integrationId, ?int $userId = null): void
    {
        if ($companyId <= 0 || $integrationId <= 0 || ($userId !== null && $userId <= 0)) {
            throw new DomainException('No se pudo identificar la empresa o la integración.');
        }
    }
}
