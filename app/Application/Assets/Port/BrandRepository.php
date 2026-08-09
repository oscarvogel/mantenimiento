<?php

declare(strict_types=1);

namespace App\Application\Assets\Port;

use App\Domain\Assets\Brand;

interface BrandRepository
{
    public function nameExists(int $companyId, string $name, ?int $excludingId = null): bool;
    public function add(Brand $brand, int $actorUserId): int;
    public function findForUpdate(int $companyId, int $brandId): ?Brand;
    public function save(Brand $brand, int $actorUserId): void;
    public function findActiveById(int $companyId, int $brandId): ?Brand;
    public function hasActiveModels(int $companyId, int $brandId): bool;
}
