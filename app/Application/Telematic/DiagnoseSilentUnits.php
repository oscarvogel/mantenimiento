<?php

declare(strict_types=1);

namespace App\Application\Telematic;

use App\Application\Notifications\Port\NotificationClock;
use App\Application\Telematic\Port\EquipmentTelemetryCatalog;
use App\Application\Telematic\Port\FleetTelemetryGatewayRegistry;
use App\Application\Telematic\Port\TelemetryIntegrationCatalog;
use App\Domain\Notifications\NotifiableEvent;
use App\Domain\Notifications\NotificationSeverity;
use App\Domain\Telematic\CoberturaEquipo;
use App\Domain\Telematic\EstadoSenal;
use App\Domain\Telematic\FuenteSenal;
use DateTimeImmutable;

/**
 * Evalúa la cobertura de telemetría de la flota y publica lo que corresponde.
 *
 * Distingue dos hechos que un único evento mezclaría:
 *
 * - `equipo.sin_telemetria`: el equipo tiene fuentes y NINGUNA trae señal
 *   fresca. Hay un hueco real de monitoreo y hay que actuar.
 * - `fuente.telemetria_caida`: una fuente dejó de responder pero otra sigue
 *   viva. El equipo está cubierto, así que es informativo: sirve para saber
 *   qué integración hay que arreglar, no para alarmar sobre la flota.
 *
 * Sin esa separación, un camión con dos sistemas reporta una sola caída y
 * además seuduplica: se generan dos eventos para el mismo equipo porque cada
 * fuente tiene su propio ciclo.
 */
final readonly class DiagnoseSilentUnits
{
    public const TYPE_SIN_TELEMETRIA = 'equipo.sin_telemetria';
    public const TYPE_FUENTE_CAIDA = 'fuente.telemetria_caida';

    public function __construct(
        private EquipmentTelemetryCatalog $equipment,
        private TelemetryIntegrationCatalog $integrations,
        private FleetTelemetryGatewayRegistry $gateways,
        private NotificationClock $clock,
        private int $thresholdHours = 24,
    ) {
    }

    /** @return list<NotifiableEvent> */
    public function execute(): array
    {
        if ($this->thresholdHours <= 0) {
            return [];
        }

        $coverages = $this->equipment->coveredEquipment();
        if ($coverages === []) {
            return [];
        }

        $signals = $this->fetchAllSignals();
        $now = $this->clock->now();
        $events = [];

        foreach ($coverages as $coverage) {
            $resolved = $this->resolve($coverage, $signals);

            if ($resolved->estaSinMonitorear($now, $this->thresholdHours)) {
                $events[] = $this->sinTelemetria($resolved, $now);

                continue;
            }

            foreach ($this->fuentesCaidasDe($resolved, $now) as $fuente) {
                $events[] = $this->fuenteCaida($resolved, $fuente, $now);
            }
        }

        return $events;
    }

    /**
     * Consulta cada integración activa una vez. Si el proveedor no está
     * registrado, o el proveedor está caído, se sigue adelante: una
     * integración que falla no puede voltear todo el ciclo de notificaciones.
     *
     * @return array<int, array<string, EstadoSenal>> integrationId => señales
     */
    private function fetchAllSignals(): array
    {
        $signals = [];

        foreach ($this->integrations->active() as $integration) {
            try {
                $gateway = $this->gateways->forProvider($integration->provider());
                $signals[$integration->id()] = $gateway->fetchFor($integration->id());
            } catch (\Throwable) {
                $signals[$integration->id()] = [];
            }
        }

        return $signals;
    }

    /** Reemplaza cada fuente por una con su estado ya resuelto. */
    private function resolve(CoberturaEquipo $coverage, array $signals): CoberturaEquipo
    {
        $resolved = [];
        foreach ($coverage->fuentes() as $fuente) {
            $estado = $signals[$fuente->integrationId()][$fuente->unidadExterna()] ?? null;
            $resolved[] = new FuenteSenal(
                $fuente->integrationId(),
                $fuente->provider(),
                $fuente->integrationName(),
                $fuente->unidadExterna(),
                $fuente->rol(),
                $estado,
            );
        }

        return new CoberturaEquipo(
            $coverage->companyId(),
            $coverage->branchId(),
            $coverage->equipmentId(),
            $coverage->code(),
            $resolved,
        );
    }

    /**
     * Sólo interesan las fuentes caídas cuando el equipo sigue cubierto. Si ya
     * se emitió "sin telemetría", avisar además por cada fuente sería ruido.
     *
     * @return list<FuenteSenal>
     */
    private function fuentesCaidasDe(CoberturaEquipo $coverage, DateTimeImmutable $now): array
    {
        if ($coverage->estaSinMonitorear($now, $this->thresholdHours)) {
            return [];
        }

        return $coverage->fuentesCaidas($now, $this->thresholdHours);
    }

    private function sinTelemetria(CoberturaEquipo $coverage, DateTimeImmutable $now): NotifiableEvent
    {
        $ultima = $coverage->ultimaSenalEn();

        $summary = 'Ninguna de sus ' . count($coverage->fuentes()) . ' fuentes reporta. Última señal: '
            . ($ultima === null ? 'nunca registrada' : $ultima->format('d/m/Y H:i'));

        if ($ultima !== null) {
            $summary .= ' · ' . $this->elapsed($ultima, $now);
        }

        return new NotifiableEvent(
            $coverage->companyId(),
            $coverage->branchId(),
            self::TYPE_SIN_TELEMETRIA,
            NotificationSeverity::WARNING,
            'Equipo sin telemetría: ' . $coverage->code(),
            $summary,
            'equipo',
            (string) $coverage->equipmentId(),
            self::TYPE_SIN_TELEMETRIA . ':empresa:' . $coverage->companyId()
                . ':equipo:' . $coverage->equipmentId()
                . ':ciclo:' . $coverage->ciclo(),
            $this->equipmentUrl($coverage->equipmentId()),
            $now,
        );
    }

    private function fuenteCaida(CoberturaEquipo $coverage, FuenteSenal $fuente, DateTimeImmutable $now): NotifiableEvent
    {
        $ultima = $fuente->reportaEn();
        $estado = $fuente->estado();

        $summary = $fuente->integrationName() . ' (' . $fuente->provider() . ') dejó de reportar. '
            . 'Última señal: ' . ($ultima === null ? 'nunca registrada' : $ultima->format('d/m/Y H:i'))
            . '. El equipo sigue cubierto por otra fuente.';

        return new NotifiableEvent(
            $coverage->companyId(),
            $coverage->branchId(),
            self::TYPE_FUENTE_CAIDA,
            NotificationSeverity::INFO,
            'Fuente de telemetría caída: ' . $coverage->code(),
            $summary,
            'integracion_telemetria',
            $fuente->integrationId(),
            self::TYPE_FUENTE_CAIDA . ':empresa:' . $coverage->companyId()
                . ':integracion:' . $fuente->integrationId()
                . ':unidad:' . $fuente->unidadExterna()
                . ':ciclo:' . $estado->ciclo(),
            $this->equipmentUrl($coverage->equipmentId()),
            $now,
        );
    }

    private function providerOf(int $integrationId): string
    {
        foreach ($this->equipment->coveredEquipment() as $coverage) {
            foreach ($coverage->fuentes() as $fuente) {
                if ($fuente->integrationId() === (string) $integrationId) {
                    return $fuente->provider();
                }
            }
        }

        return '';
    }

    private function elapsed(DateTimeImmutable $ultima, DateTimeImmutable $now): string
    {
        $minutos = intdiv($ultima->getTimestamp() - $now->getTimestamp(), -60);
        $horas = intdiv($minutos, 60);

        if ($horas < 1) {
            return 'sin reportar hace ' . $minutos . ' min';
        }

        $dias = intdiv($horas, 24);

        if ($dias < 2) {
            return 'sin reportar hace ' . $horas . ($horas === 1 ? ' hora' : ' horas');
        }

        return 'sin reportar hace ' . $dias . ($dias === 1 ? ' día' : ' días');
    }

    private function equipmentUrl(int $equipmentId): string
    {
        return (string) parse_url(
            base_url('mantenimiento/equipos/' . $equipmentId),
            PHP_URL_PATH,
        );
    }
}