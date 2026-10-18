<?php

declare(strict_types=1);

namespace App\Application\TripImports\Port;

interface TripPackage
{
    public function origenArchivo(): string;

    public function origenSha256(): string;

    public function generadoEn(): string;

    /** @return list<string> */
    public function tables(): array;

    public function hasTable(string $table): bool;

    public function declaredRows(string $table): int;

    /** @return iterable<array<string,string|null>> */
    public function rows(string $table): iterable;

    public function close(): void;
}
