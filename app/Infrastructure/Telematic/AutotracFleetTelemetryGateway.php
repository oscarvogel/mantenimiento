<?php

declare(strict_types=1);

namespace App\Infrastructure\Telematic;

use App\Application\Telematic\Port\AutotracApi;
use App\Application\Telematic\Port\FleetTelemetryGateway;
use App\Application\Telematic\Port\TelemetryCredentialStore;
use App\Domain\Telematic\EstadoSenal;
use Throwable;

final class AutotracFleetTelemetryGateway implements FleetTelemetryGateway
{
    public function __construct(
        private readonly TelemetryCredentialStore $credentials,
        private readonly AutotracApi $api,
        private readonly AutotracSnapshotMapper $mapper = new AutotracSnapshotMapper(),
    ) {
    }

    public function fetchFor(int $integrationId): array
    {
        $stored = $this->credentials->credentials($integrationId);
        $config = json_decode($stored['token'], true);
        if (! is_array($config)) {
            throw new \RuntimeException('A configuração da integração Autotrac está inválida.');
        }
        $config['endpoint'] = $stored['endpoint'];

        try {
            $states = [];
            foreach ($this->api->vehicles($config) as $vehicle) {
                $code = $vehicle['Code'] ?? null;
                if (! is_numeric($code) || (int) $code <= 0) {
                    continue;
                }
                $vehicleCode = (int) $code;
                $instant = $this->mapper->map(
                    $this->api->positions($config, $vehicleCode),
                    $this->api->expandedAlerts($config, $vehicleCode),
                );
                $states[(string) $vehicleCode] = new EstadoSenal((string) $vehicleCode, $instant);
            }
            $this->credentials->registerSuccess($integrationId, date('Y-m-d H:i:s'));

            return $states;
        } catch (Throwable $exception) {
            $this->credentials->registerFailure($integrationId, 'Falha ao consultar a API ATIC.', date('Y-m-d H:i:s'));
            throw $exception;
        }
    }
}
