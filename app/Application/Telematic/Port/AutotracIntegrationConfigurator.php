<?php

declare(strict_types=1);

namespace App\Application\Telematic\Port;

use App\Application\Telematic\ConfigureTelemetryIntegrationResult;

interface AutotracIntegrationConfigurator
{
    /** @param array{subscriptionKey:string,username:string,password:string,accountCode:int} $credentials */
    public function configure(
        int $companyId,
        int $userId,
        string $name,
        array $credentials,
    ): ConfigureTelemetryIntegrationResult;
}
