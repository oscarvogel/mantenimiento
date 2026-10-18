<?php

declare(strict_types=1);

namespace App\Infrastructure\TripImports;

use App\Application\TripImports\Port\TripPackage;
use App\Legacy\Transporte\LegacyTransportPackage;
use DomainException;
use RuntimeException;

final class LegacyTripPackage implements TripPackage
{
    public function __construct(private readonly LegacyTransportPackage $package)
    {
    }

    public function origenArchivo(): string
    {
        return $this->package->origenArchivo();
    }

    public function origenSha256(): string
    {
        return $this->package->origenSha256();
    }

    public function generadoEn(): string
    {
        return $this->package->generadoEn();
    }

    public function tables(): array
    {
        return $this->package->tablas();
    }

    public function hasTable(string $table): bool
    {
        return in_array($table, $this->package->tablas(), true);
    }

    public function declaredRows(string $table): int
    {
        return $this->package->filasDeclaradas($table);
    }

    public function rows(string $table): iterable
    {
        try {
            yield from $this->package->filas($table);
        } catch (RuntimeException $exception) {
            throw new DomainException($exception->getMessage(), 0, $exception);
        }
    }

    public function close(): void
    {
        $this->package->cerrar();
    }
}
