<?php

declare(strict_types=1);

namespace App\Presentation\ReadingControl;

use App\Application\ReadingControl\EquipmentReadingControlRow;

final class ReadingControlPayload
{
    /**
     * @param array{items:list<EquipmentReadingControlRow>,total:int,pagination:array<string,mixed>} $data
     * @param array<string,mixed> $filters
     */
    public function build(
        array $data,
        array $filters,
        array $branches,
        array $types,
        bool $canClaim,
    ): array {
        return [
            'results' => array_map(static fn (EquipmentReadingControlRow $item): array => [
                'equipmentId' => $item->equipmentId,
                'equipmentCode' => $item->equipmentCode,
                'equipmentPlate' => $item->equipmentPlate,
                'typeName' => $item->typeName,
                'branchId' => $item->branchId,
                'branchName' => $item->branchName,
                'controlsKm' => $item->controlsKm,
                'driverEmployeeId' => $item->driverEmployeeId,
                'driverName' => $item->driverName,
                'driverPhone' => $item->driverPhone,
                'lastKm' => $item->lastKm,
                'lastReadingAt' => $item->lastReadingAt,
                'daysSinceLastReading' => $item->daysSinceLastReading,
                'lastClaimDeliveryId' => $item->lastClaimDeliveryId,
                'lastClaimAt' => $item->lastClaimAt,
                'lastClaimByUser' => $item->lastClaimByUser,
                'lastClaimStatus' => $item->lastClaimStatus,
                'lastClaimInstanceId' => $item->lastClaimInstanceId,
                'hasDriver' => $item->hasDriver(),
                'hasValidPhone' => $item->hasValidPhone(),
                'hasReading' => $item->hasReading(),
            ], $data['items']),
            'total' => $data['total'],
            'pagination' => $data['pagination'],
            'filters' => $filters,
            'catalogs' => [
                'branches' => $branches,
                'types' => $types,
                'statusOptions' => [
                    ['key' => 'all', 'label' => 'Todos'],
                    ['key' => 'today', 'label' => 'Cargaron hoy'],
                    ['key' => 'not_today', 'label' => 'Sin cargar hoy'],
                    ['key' => 'gt_3', 'label' => 'Más de 3 días'],
                    ['key' => 'gt_7', 'label' => 'Más de 7 días'],
                ],
            ],
            'canClaim' => $canClaim,
            'routes' => [
                'index' => base_url('mantenimiento/lecturas/control'),
                'claim' => base_url('mantenimiento/lecturas/control/reclamar'),
                'quickReadings' => base_url('mantenimiento/lecturas/rapidas'),
                'equipmentDetail' => base_url('mantenimiento/equipos'),
            ],
            'csrf' => [
                'name' => csrf_token(),
                'hash' => csrf_hash(),
            ],
        ];
    }
}