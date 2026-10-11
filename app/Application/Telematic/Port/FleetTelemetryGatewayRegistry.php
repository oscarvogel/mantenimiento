<?php

declare(strict_types=1);

namespace App\Application\Telematic\Port;

use RuntimeException;

/**
 * Resuelve el adaptador que corresponde a cada proveedor.
 *
 * Sumar un proveedor nuevo es registrar una implementación acá. Ni el dominio
 * ni los casos de uso cambian, y esa es toda la gracia del registro: Wialon,
 * Gestya y lo que siga conviven sin condicionales repartidos por el código.
 */
interface FleetTelemetryGatewayRegistry
{
    /** Identificadores de proveedor. Es el vocabulario con el que se rotula una integración. */
    public const PROVIDER_WIALON = 'wialon';
    public const PROVIDER_GESTYA = 'gestya';

    public function supports(string $provider): bool;

    /** @throws RuntimeException si el proveedor no está registrado. */
    public function forProvider(string $provider): FleetTelemetryGateway;
}