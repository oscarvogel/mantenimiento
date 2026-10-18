<?php

declare(strict_types=1);

namespace App\Application\TripImports;

final class TripChildRow
{
    /**
     * @param array<string,mixed> $data
     * @param list<array{field:string,message:string}> $errors
     * @param list<array{field:string,message:string}> $warnings
     */
    public function __construct(
        public readonly string $table,
        public readonly string $tripKey,
        public readonly array $data,
        public readonly array $errors,
        public readonly array $warnings,
    ) {
    }
}
