<?php

declare(strict_types=1);

namespace App\Application\Telematic\Port;

use App\Application\Telematic\ConfigureTelemetryIntegrationResult;

interface TelemetryIntegrationConfigurator
{
    public function configure(
        int $companyId,
        int $userId,
        string $provider,
        string $name,
        string $token,
    ): ConfigureTelemetryIntegrationResult;
}
