<?php

declare(strict_types=1);

namespace App\Application\TripMasters\Port;

interface TsaTripMasterCatalogSeeder
{
    /**
     * @param array<string,array{table:string,rows:list<array<string,string>>}> $catalogs
     * @return array<string,int> inserted rows by catalog label
     */
    public function insertMissing(array $catalogs, int $actorUserId): array;
}
