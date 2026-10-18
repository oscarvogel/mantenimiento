<?php

declare(strict_types=1);

namespace App\Application\TripImports\Port;

interface TripPackageReader
{
    public function open(string $path): TripPackage;
}
