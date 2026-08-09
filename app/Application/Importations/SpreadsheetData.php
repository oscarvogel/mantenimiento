<?php

declare(strict_types=1);

namespace App\Application\Importations;

final class SpreadsheetData
{
    /**
     * @param list<string>                     $headers
     * @param list<array<string, string|null>> $rows
     */
    public function __construct(
        public readonly array $headers,
        public readonly array $rows,
    ) {
    }
}
