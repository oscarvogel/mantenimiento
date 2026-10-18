<?php

declare(strict_types=1);

namespace App\Application\TripImports;

final class TripRow
{
    /**
     * @param array<string,mixed> $data
     * @param list<array{field:string,message:string}> $errors
     * @param list<array{field:string,message:string}> $warnings
     */
    public function __construct(
        public readonly string $key,
        public readonly string $domain,
        public readonly array $data,
        public readonly array $errors,
        public readonly array $warnings,
    ) {
    }
}
