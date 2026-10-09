<?php

declare(strict_types=1);

namespace App\Application\Telematic;

use App\Application\Notifications\Port\NotificationClock;
use App\Application\Telematic\Port\EquipmentTelemetryCatalog;
use App\Application\Telematic\Port\FleetTelemetryGatewayRegistry;
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
        private NotificationClock $clock,
    ) {
    }

    /** @return array{integraciones:int, instantaneas:int, ausentes:int, falhas:int} */
    public function execute(): array
    {
        $summary = ['integraciones' => 0, 'instantaneas' => 0, 'ausentes' => 0, 'fallas' => 0];
        $now = $this->clock->now();
        $registradaEn = $now->format('Y-m-d H:i:s');

        // Índice de vínculos por integración, para resolver a qué equipo
        // pertenece cada unidad externa que volvió del proveedor.
        $vinculos = [];
        foreach ($this->equipment->coveredEquipment() as $coverage) {
            foreach ($coverage->fuentes() as $fuente) {
                $vinculos[$fuente->integrationId()][$fuente->unidadExterna()][] = $coverage;
            }
        }

        if ($vinculos === []) {
            return $summary;
        }

        foreach ($this->integrations->active() as $integration) {
            $integrationId = (string) $integration->id();

            try {
                $gateway = $this->gateways->forProvider($integration->provider());
                $states = $gateway->fetchFor($integration->id());
            } catch (\Throwable) {
                $summary['fallas']++;
                $summary['ausentes'] += $this->store->markAbsent($integration->id(), [], $registradaEn);

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
                    $this->store->save(new InstantaneaRegistrada(
                        $coverage->companyId(),
                        $coverage->branchId(),
                        $coverage->equipmentId(),
                        $integration->id(),
                        $integration->provider(),
                        (string) $unidadExterna,
                        $instantanea,
                        $registradaEn,
                    ));
                    $summary['instantaneas']++;
                }
            }

            $summary['ausentes'] += $this->store->markAbsent($integration->id(), $observadas, $registradaEn);
        }

        return $summary;
    }
}