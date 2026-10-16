<?php

declare(strict_types=1);

namespace App\Application\Customers\Port;

interface CustomerImportReader
{
    public function read(string $path): array;
}
