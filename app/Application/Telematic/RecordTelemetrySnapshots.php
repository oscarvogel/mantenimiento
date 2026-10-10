<?php

declare(strict_types=1);

namespace App\Application\Telematic;

use App\Application\Notifications\Port\NotificationClock;
use App\Application\Telematic\Port\EquipmentTelemetryCatalog;
use App\Application\Telematic\Port\FleetTelemetryGateway;
use App\Application\Telematic\Port\FleetTelemetryGatewayRegistry;
use App\Application\Telematic\Port\LectorHistoricoTelemetria;
use App\Application\Telematic\Port\TelemetryIntegrationCatalog;
use App\Application\Telematic\Port\TelemetryRefresher;
use App\Application\Telematic\Port\TelemetrySnapshotStore;

/**
 * Toma la instantánea de cada fuente vinculada y la guarda.
 *
 * Corre antes de evaluar alertas, para que la ficha y el mapa tengan contra
 * qué leer. Es de sólo lectura contra el proveedor: no toca kilometraje ni
 * preventivos. Alimentar las lecturas es otra decisión, y de una que hoy
 * frenamos por el hueco de validación del cierre de OT.
 *
 * El resultado no depende de que el proveedor conteste: una integración caída
 * simplemente no aporta instantáneas y las que ya había quedan con su
 * última observación, marcadas como ausentes.
 */
final readonly class RecordTelemetrySnapshots implements TelemetryRefresher
{
    public function __construct(
        private EquipmentTelemetryCatalog $equipment,
        private TelemetryIntegrationCatalog $integrations,
        private FleetTelemetryGatewayRegistry $gateways,
        private TelemetrySnapshotStore $store,
        private RegistrarMuestras $muestras,
        private NotificationClock $clock,
        private int $companyId,
        private ?SincronizarBucketsTelemetria $sincronizadorHistorico = null,
    ) {
    }

    /** @return array{integraciones:int, instantaneas:int, muestras:int, ausentes:int, fallas:int, bucketsNuevos:int, bucketsYaExistentes:int} */
    public function execute(): array
    {
        $summary = [
            'integraciones' => 0,
            'instantaneas' => 0,
            'muestras' => 0,
            'ausentes' => 0,
            'fallas' => 0,
            'bucketsNuevos' => 0,
            'bucketsYaExistentes' => 0,
        ];
        $now = $this->clock->now();
        $registradaEn = $now->format('Y-m-d H:i:s');

        // Índice de vínculos por integración, para resolver a qué equipo
        // pertenece cada unidad externa que volvió del proveedor.
        $vinculos = [];
        $unidadesHistoricas = [];
        foreach ($this->equipment->coveredEquipmentFor($this->companyId) as $coverage) {
            foreach ($coverage->fuentes() as $fuente) {
                $integrationId = $fuente->integrationId();
                $unidad = $fuente->unidadExterna();
                $vinculos[$integrationId][$unidad][] = $coverage;

                // Una unidad histórica tiene un solo dueño por clave de base.
                // El vínculo principal prevalece; ante datos históricos
                // ambiguos, el ID menor conserva una elección reproducible.
                $actual = $unidadesHistoricas[$integrationId][$unidad] ?? null;
                $esPrincipal = strtoupper($fuente->rol()) === 'PRINCIPAL';
                if ($actual === null
                    || ($esPrincipal && ! $actual['principal'])
                    || ($esPrincipal === $actual['principal'] && $coverage->equipmentId() < $actual['equipoId'])
                ) {
                    $unidadesHistoricas[$integrationId][$unidad] = [
                        'equipoId' => $coverage->equipmentId(),
                        'principal' => $esPrincipal,
                    ];
                }
            }
        }

        if ($vinculos === []) {
            return $summary;
        }

        foreach ($this->integrations->activeFor($this->companyId) as $integration) {
            if ($integration->companyId() !== $this->companyId) {
                continue;
            }

            $integrationId = (string) $integration->id();

            try {
                $gateway = $this->gateways->forProvider($integration->provider());
            } catch (\Throwable) {
                $summary['fallas']++;
                $summary['ausentes'] += $this->store->markAbsent($integration->id(), [], $registradaEn);

                continue;
            }

            try {
                $states = $gateway->fetchFor($integration->id());
            } catch (\Throwable) {
                $summary['fallas']++;
                $summary['ausentes'] += $this->store->markAbsent($integration->id(), [], $registradaEn);
                $resultadoHistorico = $this->sincronizarHistorico(
                    $gateway,
                    $integration->id(),
                    $integration->provider(),
                    $unidadesHistoricas[$integrationId] ?? [],
                    $now,
                );
                $summary['fallas'] += $resultadoHistorico['fallo'] ? 1 : 0;
                $summary['bucketsNuevos'] += $resultadoHistorico['insertados'];
                $summary['bucketsYaExistentes'] += $resultadoHistorico['yaExistentes'];

                continue;
            }

            $summary['integraciones']++;
            $observadas = [];

            foreach ($states as $unidadExterna => $estado) {
                $instantanea = $estado->instantanea();
                if ($instantanea === null) {
                    continue;
                }

                $observadas[] = (string) $unidadExterna;

                foreach ($vinculos[$integrationId][(string) $unidadExterna] ?? [] as $coverage) {
                    $registrada = new InstantaneaRegistrada(
                        $coverage->companyId(),
                        $coverage->branchId(),
                        $coverage->equipmentId(),
                        $integration->id(),
                        $integration->provider(),
                        (string) $unidadExterna,
                        $instantanea,
                        $registradaEn,
                    );

                    // El snapshot se sigue sobrescribiendo: es estado actual y
                    // alimenta mapa y ficha. La muestra se agrega sin tocarlo.
                    $this->store->save($registrada);

                    // Sólo cuenta como muestra nueva si se agregó de verdad. Un
                    // reintento del cron con el mismo estado del proveedor no
                    // escribe nada y no debe verse como que ingirió algo.
                    $summary['muestras'] += $this->muestras->registrar($registrada);
                    $summary['instantaneas']++;
                }
            }

            $summary['ausentes'] += $this->store->markAbsent($integration->id(), $observadas, $registradaEn);

            $resultadoHistorico = $this->sincronizarHistorico(
                $gateway,
                $integration->id(),
                $integration->provider(),
                $unidadesHistoricas[$integrationId] ?? [],
                $now,
            );
            $summary['fallas'] += $resultadoHistorico['fallo'] ? 1 : 0;
            $summary['bucketsNuevos'] += $resultadoHistorico['insertados'];
            $summary['bucketsYaExistentes'] += $resultadoHistorico['yaExistentes'];
        }

        return $summary;
    }

    /**
     * @param array<string,array{equipoId:int,principal:bool}> $asignaciones
     * @return array{fallo:bool,insertados:int,yaExistentes:int}
     */
    private function sincronizarHistorico(
        FleetTelemetryGateway $gateway,
        int $integrationId,
        string $provider,
        array $asignaciones,
        \DateTimeImmutable $ahora,
    ): array {
        if ($this->sincronizadorHistorico === null || ! $gateway instanceof LectorHistoricoTelemetria) {
            return ['fallo' => false, 'insertados' => 0, 'yaExistentes' => 0];
        }

        $unidades = [];
        foreach ($asignaciones as $unidad => $asignacion) {
            $unidades[$unidad] = $asignacion['equipoId'];
        }

        try {
            $resultado = $this->sincronizadorHistorico->sincronizar(
                $this->companyId,
                $integrationId,
                $provider,
                $unidades,
                $ahora,
                $gateway,
            );

            return ['fallo' => false] + $resultado;
        } catch (\Throwable) {
            // La captura actual puede fallar sin impedir el histórico y
            // viceversa. El cursor intacto permite reintentar el histórico.
            return ['fallo' => true, 'insertados' => 0, 'yaExistentes' => 0];
        }
    }
}
