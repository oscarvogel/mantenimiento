<?php

declare(strict_types=1);

namespace App\Domain\TripImports;

final class TripKey
{
    public static function make(?string $cuit, ?string $domain, int|string|null $number): string
    {
        $cuit = trim((string) $cuit);
        $domain = trim((string) $domain);
        $number = (int) ($number ?? 0);
        if ($cuit === '' || $domain === '' || $number <= 0) {
            return '';
        }

        return $cuit . '|' . $domain . '|' . $number;
    }
}
