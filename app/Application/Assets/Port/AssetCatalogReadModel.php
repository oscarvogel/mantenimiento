<?php

declare(strict_types=1);

namespace App\Application\Assets\Port;

interface AssetCatalogReadModel
{
    /** @return array{brands:list<array<string,mixed>>,models:list<array<string,mixed>>,types:list<array<string,mixed>>} */
    public function list(int $companyId, bool $includeInactive): array;
}
