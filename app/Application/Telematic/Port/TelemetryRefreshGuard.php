<?php

declare(strict_types=1);

namespace App\Application\Telematic\Port;

use DateTimeImmutable;

/**
 * Consulta cuándo se leyó por última vez una integración.
 *
 * Existe para el enfriamiento del refresco manual. Los límites de Wialon son
 * por IP y no por token: diez intentos fallidos en un minuto bloquean la IP
 * completa, que en un hosting compartido es un problema para todos los sitios
 * que salen por la misma salida.
 */
interface TelemetryRefreshGuard
{
    public function ultimaLecturaDe(int $integrationId): ?DateTimeImmutable;
}