<?php

declare(strict_types=1);

namespace App\Application\Telematic;

use App\Application\Telematic\Port\TelemetryIntegrationConfigurator;
use DomainException;

final class ConfigureTelemetryIntegration
{
    public function __construct(private readonly TelemetryIntegrationConfigurator $configurator)
    {
    }

    public function execute(
        int $companyId,
        int $userId,
        string $provider,
        ?string $name,
        string $token,
    ): ConfigureTelemetryIntegrationResult {
        $provider = strtolower(trim($provider));
        if ($companyId <= 0 || $userId <= 0) {
            throw new DomainException('No se pudo identificar la empresa para guardar la integración.');
        }
        if ($provider !== 'wialon') {
            throw new DomainException('Ese proveedor todavía no está disponible para conectar.');
        }

        $token = trim($token);
        if ($token === '' || strlen($token) > 4096) {
            throw new DomainException('Pegá un token válido de hasta 4096 caracteres.');
        }

        $name = trim((string) $name);
        if ($name === '') {
            $name = 'Wialon';
        }
        if (mb_strlen($name) > 100) {
            throw new DomainException('El nombre de la cuenta no puede superar 100 caracteres.');
        }

        return $this->configurator->configure($companyId, $userId, $provider, $name, $token);
    }
}
