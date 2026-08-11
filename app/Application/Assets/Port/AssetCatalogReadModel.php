<?php

declare(strict_types=1);

namespace App\Application\Assets\Port;

interface AssetCatalogReadModel
{
    /** @return array{brands:list<array<string,mixed>>,models:list<array<string,mixed>>,types:list<array<string,mixed>>} */
    public function list(int $companyId, bool $includeInactive): array;

    /** @return array{brands:array{items:list<array<string,mixed>>,total:int,page:int,perPage:int,totalPages:int},models:array{items:list<array<string,mixed>>,total:int,page:int,perPage:int,totalPages:int}} */
    public function paginateManagement(
        int $companyId,
        int $brandPage,
        int $brandsPerPage,
        int $modelPage,
        int $modelsPerPage,
    ): array;
}
