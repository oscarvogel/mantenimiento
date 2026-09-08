<?php

declare(strict_types=1);

namespace App\Application\Importations\Port;

use App\Application\Importations\ExpirationImportData;

interface ExpirationImportGateway
{
    public function isDuplicate(ExpirationImportData $data): bool;

    public function import(ExpirationImportData $data): int;
}
