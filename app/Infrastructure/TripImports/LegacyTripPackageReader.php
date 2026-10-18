<?php

declare(strict_types=1);

namespace App\Infrastructure\TripImports;

use App\Application\TripImports\Port\TripPackage;
use App\Application\TripImports\Port\TripPackageReader;
use App\Legacy\Transporte\LegacyTransportPackage;
use DomainException;
use RuntimeException;

final class LegacyTripPackageReader implements TripPackageReader
{
    public function open(string $path): TripPackage
    {
        try {
            return new LegacyTripPackage(LegacyTransportPackage::fromZip($path));
        } catch (RuntimeException $exception) {
            throw new DomainException($exception->getMessage(), 0, $exception);
        }
    }
}
