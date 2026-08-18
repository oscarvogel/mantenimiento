<?php

declare(strict_types=1);

namespace App\Application\PreventiveMaintenance\Port;

interface MaintenanceServiceCatalog
{
    /** @return list<array<string,mixed>> */
    public function listForCompany(int $companyId): array;

    /** @param array<string,mixed> $data */
    public function create(int $companyId, int $actorId, array $data): int;

    /** @param array<string,mixed> $data */
    public function update(int $companyId, int $serviceId, int $actorId, array $data): void;

    public function setActive(int $companyId, int $serviceId, int $actorId, bool $active): void;

    /** @param array<string,mixed> $data @return array<string,mixed> */
    public function createMaterial(int $companyId, int $serviceId, int $actorId, array $data): array;

    /** @param array<string,mixed> $data @return array<string,mixed> */
    public function updateMaterial(int $companyId, int $serviceId, int $materialId, int $actorId, array $data): array;

    public function setMaterialActive(int $companyId, int $serviceId, int $materialId, int $actorId, bool $active): void;
}
