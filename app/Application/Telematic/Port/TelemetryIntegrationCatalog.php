<?php

declare(strict_types=1);

namespace App\Application\Telematic\Port;

use App\Application\Telematic\IntegracionTelematrica;

/**
 * Integraciones de telemetría activas.
 *
 * Se expone el identificador y el proveedor, nunca la credencial: el token se
 * resuelve y se descifra dentro de Infrastructure, para que la capa de
 * aplicación no llegue a manejar secretos de clientes.
 */
interface TelemetryIntegrationCatalog
{
    /** @return list<IntegracionTelematrica> Integraciones activas de UNA empresa. */
    public function activeFor(int $companyId): array;
}