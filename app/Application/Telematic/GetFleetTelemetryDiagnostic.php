<?php

declare(strict_types=1);

namespace App\Application\Telematic;

use App\Application\Identity\ActorContext;
use App\Application\Notifications\Port\NotificationClock;
use App\Application\Telematic\Port\ConsumoConfigStore;
use App\Application\Telematic\Port\FleetTelemetryBoardReader;
use App\Application\Telematic\Port\SerieMuestrasReader;
use App\Domain\Telematic\Consumo\PoliticaConsumo;
use App\Domain\Telematic\Consumo\ViajeConsumido;
use App\Domain\Telematic\MuestraEquipo;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;

/** Read-only, branch-scoped comparison of persisted telemetry and derived trips for one day. */
final readonly class GetFleetTelemetryDiagnostic
{
    public function __construct(
        private FleetTelemetryBoardReader $fleet,
        private SerieMuestrasReader $samples,
        private CalcularConsumoPorEquipo $consumption,
        private ConsumoConfigStore $config,
        private NotificationClock $clock,
    ) {
    }

    /** @return array<string,mixed> */
    public function execute(ActorContext $actor, int $equipmentId, string $date): array
    {
        if ($actor->isSuperAdmin() || $actor->companyId() === null || ! $actor->hasPermission('equipos.ver')) {
            throw new DomainException('No tenés permiso para consultar la telemetría de esta flota.');
        }

        if ($equipmentId <= 0) {
            throw new DomainException('Elegí un equipo válido para revisar sus lecturas.');
        }

        $timezone = new DateTimeZone((new \Config\App())->appTimezone);
        $from = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $timezone);
        $dateErrors = DateTimeImmutable::getLastErrors();
        if ($from === false || $from->format('Y-m-d') !== $date
            || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))) {
            throw new DomainException('Ingresá una fecha válida para consultar las lecturas.');
        }

        $until = $from->add(new DateInterval('P1D'))->modify('-1 second');
        if ($from > $this->clock->now()->setTimezone($timezone)) {
            throw new DomainException('No se puede consultar una fecha futura.');
        }

        $branchIds = $actor->hasAllCompanyBranches() ? null : array_values(array_unique($actor->branchIds()));
        $board = $branchIds === [] ? ['units' => []] : $this->fleet->read($actor->companyId(), $branchIds);
        $equipment = null;
        foreach ($board['units'] ?? [] as $unit) {
            if ((int) ($unit['equipmentId'] ?? 0) === $equipmentId) {
                $equipment = $unit;
                break;
            }
        }

        if ($equipment === null) {
            throw new DomainException('No tenés acceso a ese equipo o no está vinculado a telemetría.');
        }

        $samples = $this->samples->serie($actor->companyId(), $equipmentId, $from, $until);
        $policy = PoliticaConsumo::desdeConfig($this->config->obtener($actor->companyId()));
        $report = $this->consumption->executeWithSamples(
            $actor->companyId(),
            $equipmentId,
            $from,
            $until,
            $samples,
            $policy,
        );

        return [
            'date' => $from->format('Y-m-d'),
            'equipment' => [
                'id' => $equipmentId,
                'label' => $equipment['plate'] ?? $equipment['code'] ?? (string) $equipmentId,
            ],
            'samples' => array_map(fn (MuestraEquipo $sample): array => $this->sample($sample, $timezone), $samples),
            'trips' => array_map(fn (ViajeConsumido $trip): array => $this->trip($trip, $timezone), $report->viajes()),
            'summary' => [
                'sampleCount' => count($samples),
                'tripCount' => count($report->viajes()),
                'coveragePercent' => (int) ($report->cobertura()->cobertura() * 100),
                'coverageSufficient' => $report->cobertura()->suficiente(),
                'coverageReason' => $report->cobertura()->motivoInsuficiente(),
                'uncalibrated' => $report->sinCalibrar(),
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function sample(MuestraEquipo $sample, DateTimeZone $timezone): array
    {
        return [
            'provider' => $sample->proveedor(),
            'externalUnitId' => $sample->unidadExterna(),
            'observedAt' => $sample->observadaEn()->setTimezone($timezone)->format('Y-m-d H:i:s'),
            'recordedAt' => $sample->registradaEn()->setTimezone($timezone)->format('Y-m-d H:i:s'),
            'delayMinutes' => $sample->demoraMinutos(),
            'fuelLiters' => $sample->combustibleLitros(),
            'kilometers' => $sample->kilometraje(),
            'hours' => $sample->horasDecimales() === null ? null : $sample->horasDecimales() / 10,
            'engineOn' => $sample->motorEncendido(),
            'speedKmh' => $sample->posicion()?->speedKmh(),
            'anomalies' => array_map(static fn ($issue): array => [
                'sensor' => $issue->concepto(),
                'value' => $issue->valorLeido(),
                'reason' => $issue->motivo(),
            ], $sample->anomalias()),
        ];
    }

    /** @return array<string,mixed> */
    private function trip(ViajeConsumido $trip, DateTimeZone $timezone): array
    {
        $journey = $trip->viaje();
        $metrics = $trip->indicadores();

        return [
            'start' => $journey->inicio()->setTimezone($timezone)->format('Y-m-d H:i:s'),
            'end' => $journey->fin()->setTimezone($timezone)->format('Y-m-d H:i:s'),
            'kilometers' => $journey->kilometraje(),
            'liters' => round($journey->litrosConsumidos(), 2),
            'litersPer100Km' => $metrics->litrosPor100Km() === null ? null : round($metrics->litrosPor100Km(), 1),
            'reconstructible' => $journey->reconstruible(),
            'reason' => $journey->motivoNoReconstruible(),
        ];
    }
}
