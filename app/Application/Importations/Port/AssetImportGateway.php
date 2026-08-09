<?php

declare(strict_types=1);

namespace App\Application\Importations\Port;

use App\Application\Importations\AssetImportData;

interface AssetImportGateway
{
    public function isDuplicate(int $companyId, string $code): bool;

    public function import(AssetImportData $data): int;
}
