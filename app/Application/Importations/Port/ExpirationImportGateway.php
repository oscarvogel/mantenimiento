<?php

declare(strict_types=1);

namespace App\Application\Importations\Port;

use App\Application\Importations\ExpirationImportData;

/**
 * Puerto de salida hacia el bounded context de vencimientos.
 * Importaciones no conoce sus tablas ni sus reglas de idempotencia.
 */
interface ExpirationImportGateway
{
    public function isDuplicate(ExpirationImportData $data): bool;

    public function import(ExpirationImportData $data): int;
}
