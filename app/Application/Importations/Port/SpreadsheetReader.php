<?php

declare(strict_types=1);

namespace App\Application\Importations\Port;

use App\Application\Importations\SpreadsheetData;

interface SpreadsheetReader
{
    public function read(string $privatePath, int $maximumRows = 5000): SpreadsheetData;
}
