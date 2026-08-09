<?php

declare(strict_types=1);

namespace App\Application\Importations\Port;

use App\Application\Importations\MeasurementImportData;

interface MeasurementImportGateway
{
    public function isDuplicate(MeasurementImportData $data): bool;

    public function import(MeasurementImportData $data): int;
}
