<?php

declare(strict_types=1);

namespace App\Application\Neumaticos\Port;

use App\Application\Neumaticos\NeumaticRegistryFilters;

/**
 * Lectura del padrón y de la ficha del neumático, con los nombres de catálogo
 * y sucursal ya resueltos. Siempre con la empresa y las sucursales del actor
 * (`null` = todas las de la empresa).
 */
interface NeumaticRegistryReadModel
{
    /**
     * @param list<int>|null $branchIds
     * @return array{total: int, items: list<array{id: int, number: string, status: string, size: string, year: int|null, brandName: string|null, modelName: string|null, coverTypeName: string|null, branchId: int, branchName: string, mountedOn: string|null}>}
     */
    public function search(int $companyId, ?array $branchIds, NeumaticRegistryFilters $filters, int $limit): array;

    /**
     * @param list<int>|null $branchIds
     * @return array{id: int, number: string, status: string, size: string, year: int|null, brandId: int|null, brandName: string|null, modelId: int|null, modelName: string|null, coverTypeId: int|null, coverTypeName: string|null, branchId: int, branchName: string, createdAt: string|null}|null
     */
    public function detail(int $companyId, ?array $branchIds, int $neumaticId): ?array;

    /**
     * Bitácora de estados, de la más reciente a la más antigua.
     *
     * @return list<array{from: string|null, to: string, at: string, reason: string, userName: string|null}>
     */
    public function statusLog(int $companyId, int $neumaticId): array;

    /**
     * Catálogos activos para el alta y la corrección.
     *
     * @return array{brands: list<array{id: int, name: string}>, models: list<array{id: int, name: string, brandId: int}>, coverTypes: list<array{id: int, name: string}>}
     */
    public function formOptions(int $companyId): array;
}
