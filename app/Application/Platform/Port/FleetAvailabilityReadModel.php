<?php

declare(strict_types=1);

namespace App\Application\Platform\Port;

/**
 * Lectura que combina Registro de activos (equipo activo, sucursal) y Ejecución de
 * mantenimiento (órdenes abiertas, detención). Contrato en docs/CONTRATOS_ENTRE_CONTEXTOS.md.
 */
interface FleetAvailabilityReadModel
{
    /**
     * Una fila por equipo activo y orden abierta; un equipo sin órdenes abiertas aparece
     * una vez con los campos de orden en null. Filtra por la sucursal actual del equipo.
     *
     * @param list<int>|null $branchIds null = todas las sucursales de la empresa
     * @return list<array{equipmentId:int, code:string, plate:?string, branchName:string, orderId:?int, orderNumber:?string, orderStatus:?string, openedAt:?string, outOfService:bool, stopStartedAt:?string, stopEndedAt:?string, reason:?string}>
     */
    public function rows(int $companyId, ?array $branchIds, ?int $branchId): array;

    /**
     * @param list<int>|null $branchIds
     * @return list<array{id:int, name:string}>
     */
    public function branches(int $companyId, ?array $branchIds): array;
}
