<?php

declare(strict_types=1);

namespace App\Infrastructure\Telematic;

use App\Application\Telematic\Port\FleetTelemetryGateway;
use App\Application\Telematic\Port\FleetTelemetryGatewayRegistry;
use RuntimeException;

/**
 * Registro de adaptadores por proveedor.
 *
 * Hoy hay uno solo. Cuando entre Gestya se agrega una línea y nada más: el
 * dominio y los casos de uso no se tocan, que es exactamente lo que se
 * buscaba al separar el puerto del adaptador concreto.
 */
final class CodeIgniterFleetTelemetryGatewayRegistry implements FleetTelemetryGatewayRegistry
{
    /** @var array<string, callable():FleetTelemetryGateway> */
    private array $factories;

    /** @param array<string, callable():FleetTelemetryGateway> $factories */
    public function __construct(array $factories)
    {
        $this->factories = $factories;
    }

    public function supports(string $provider): bool
    {
        return isset($this->factories[$provider]);
    }

    public function forProvider(string $provider): FleetTelemetryGateway
    {
        if (! isset($this->factories[$provider])) {
            throw new RuntimeException(sprintf(
                'El proveedor de telemetría "%s" no tiene adaptador registrado.',
                $provider,
            ));
        }

        return ($this->factories[$provider])();
    }
}