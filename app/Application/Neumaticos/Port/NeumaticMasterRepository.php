<?php

declare(strict_types=1);

namespace App\Application\Neumaticos\Port;

/**
 * Maestros del contexto: marcas, modelos, tipos de cubierta y depósitos.
 *
 * `$catalog` es una de las claves de `NeumaticMasterCatalog::CATALOGS`. Todo
 * acceso lleva la empresa; los depósitos además se filtran por las sucursales
 * del actor (`null` = todas las de la empresa).
 */
interface NeumaticMasterRepository
{
    /**
     * @param list<int>|null $branchIds
     * @return list<array<string, mixed>>
     */
    public function list(string $catalog, int $companyId, ?array $branchIds): array;

    /**
     * El nombre ya existe en la empresa, sin distinguir mayúsculas. Los modelos
     * se comparan dentro de su marca.
     */
    public function nameTaken(string $catalog, int $companyId, string $name, ?int $brandId, ?int $excludeId): bool;

    /** @param array<string, mixed> $values */
    public function create(string $catalog, int $companyId, array $values): int;

    /**
     * @param list<int>|null $branchIds
     * @param array<string, mixed> $values
     * @return bool false si el registro no existe o no está en el scope.
     */
    public function update(string $catalog, int $companyId, ?array $branchIds, int $id, array $values): bool;

    /**
     * @param list<int>|null $branchIds
     * @return bool false si el registro no existe o no está en el scope.
     */
    public function setActive(string $catalog, int $companyId, ?array $branchIds, int $id, bool $active): bool;
}
