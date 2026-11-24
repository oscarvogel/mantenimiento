<?php

declare(strict_types=1);

namespace App\Application\TripMasters\Port;

interface TripMasterRepository
{
    /**
     * "clientes" refiere al padrón canónico de la empresa; los domicilios pertenecen a Viajes.
     * @return list<array<string,mixed>>
     */
    public function list(string $catalog, int $companyId, bool $includeInactive = true): array;

    /**
     * Los clientes manuales reciben una referencia de origen única.
     * @param array<string,mixed> $data
     */
    public function add(string $catalog, int $companyId, array $data, int $userId): int;

    /** @param array<string,mixed> $data */
    public function update(string $catalog, int $companyId, int $id, array $data, int $userId): void;

    public function setActive(string $catalog, int $companyId, int $id, bool $active, int $userId): void;

    public function belongsToCompany(string $catalog, int $companyId, int $id): bool;

    public function employeeBelongsToCompany(int $companyId, int $employeeId): bool;

    public function countryIsActive(int $countryId): bool;

    public function sharedCurrencyExists(string $code): bool;

    /** @param array<string,float> $rates */
    public function saveExchangeRates(int $companyId, array $rates, int $userId): void;

    public function hasOverlappingLiquidationRate(int $companyId, int $employeeId, string $from, ?string $to, ?int $exceptId = null): bool;

    /**
     * Porcentaje de liquidación por defecto de la empresa, si tiene uno.
     * Null cuando la empresa no definió un valor: en ese caso el porcentaje
     * queda sin resolver en lugar de inventarse.
     */
    public function liquidationDefault(int $companyId): ?float;

    /** Guarda el porcentaje por defecto de la empresa; null lo deja sin definir. */
    public function saveLiquidationDefault(int $companyId, ?float $percentage, int $userId): void;
}
