<?php

declare(strict_types=1);

namespace App\Application\Telematic;

final readonly class ConfigureTelemetryIntegrationResult
{
    /** @param list<string> $unmatchedEquipment */
    public function __construct(
        public int $integrationId,
        public int $linkedEquipmentCount,
        public array $unmatchedEquipment,
    ) {
    }
}
