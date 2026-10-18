<?php

declare(strict_types=1);

namespace App\Application\TripImports\Port;

use App\Application\TripImports\TripChildRow;
use App\Application\TripImports\TripRow;

interface TripRowMapper
{
    /** @param array<string,string|null> $row */
    public function trip(array $row): TripRow;

    /** @param array<string,string|null> $row */
    public function child(string $legacyTable, array $row): TripChildRow;
}
