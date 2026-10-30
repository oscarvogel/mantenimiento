<?php

declare(strict_types=1);

namespace App\Application\Geography\Port;

interface LocalityCatalogImportStore
{
    /**
     * @param iterable<array<string, string>> $records Fully validated normalized records.
     * @return array{nuevas:int,actualizadas:int,sin_cambios:int}
     */
    public function import(string $countryCode, string $source, string $version, string $sha256, int $actorId, iterable $records): array;
}
