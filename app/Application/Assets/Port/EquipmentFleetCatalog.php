<?php

declare(strict_types=1);

namespace App\Application\Assets\Port;

/**
 * Equipos activos de la flota para elegir uno, con el scope de empresa y
 * sucursal resuelto. Responde Assets, dueño de `equipos`.
 *
 * Es un contrato aparte de `EquipmentFleetLookup` para no obligar a sus
 * consumidores actuales (Viajes) a implementar métodos que no usan. Lo
 * consume Neumáticos al colocar un neumático en un equipo.
 */
interface EquipmentFleetCatalog
{
    /**
     * @param list<int>|null $branchIds null = todas las sucursales de la empresa
     * @return list<array{id: int, branchId: int, label: string, plate: string, code: string}>
     */
    public function activeEquipment(int $companyId, ?array $branchIds): array;

    /**
     * Null si el equipo no es de la empresa, está dado de baja o no está activo.
     *
     * @return array{id: int, branchId: int, label: string, plate: string, code: string}|null
     */
    public function activeEquipmentById(int $companyId, int $equipmentId): ?array;
}
