<?php

declare(strict_types=1);

namespace App\Application\Telematic\Port;

use App\Domain\Telematic\EstadoSenal;

/**
 * Puerto hacia un proveedor concreto de telemetría.
 *
 * Cada proveedor es un adaptador distinto, pero todos exponen la misma forma:
 * estado de señal por unidad externa. Eso es lo que permite que el dominio y
 * los casos de uso no sepan si están hablando con Wialon, con Gestya o con
 * otro.
 *
 * Recibe el identificador de la integración y no sus credenciales: el
 * adaptador resuelve endpoint y token por su cuenta.
 */
interface FleetTelemetryGateway
{
    /** @return array<string, EstadoSenal> Estado por identificador de unidad externa. */
    public function fetchFor(int $integrationId): array;
}