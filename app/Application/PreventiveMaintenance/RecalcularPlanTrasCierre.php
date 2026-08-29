<?php

declare(strict_types=1);

namespace App\Application\PreventiveMaintenance;

use App\Application\PreventiveMaintenance\Port\PlanMantenimientoRepository;
use App\Application\PreventiveMaintenance\Port\ServiceTypeGateway;
use App\Domain\PreventiveMaintenance\PlanMantenimiento;
use DateTimeImmutable;
use DomainException;

final readonly class RecalcularPlanTrasCierre
{
    public function __construct(
        private PlanMantenimientoRepository $plans,
        private ServiceTypeGateway $services,
    ) {
    }

    /**
     * Participa de la transaccion iniciada por el coordinador de cierre de OT.
     *
     * La definición vigente del Servicio es siempre la fuente de verdad para
     * frecuencia, anticipación y prioridad. La asignación conserva únicamente
     * la base/última realización específica del equipo.
     *
     * @param list<int>|null $branchIds
     * @return array{proximo_km:?int,proximas_horas_decimas:?int,proxima_fecha:?string}
     */
    public function execute(
        int $companyId,
        int $planId,
        ?array $branchIds,
        DateTimeImmutable $completedAt,
        ?int $outputKm,
        ?int $outputHoursTenths,
        int $actorUserId,
    ): array {
        $plan = $this->plans->findScoped($companyId, $planId, $branchIds, true);

        if ($plan === null) {
            throw new DomainException('La asignación no existe o queda fuera del alcance del cierre.');
        }

        $definition = $this->services->findActiveDefinition($companyId, $plan->tipoServicioId());
        if ($definition === null) {
            throw new DomainException('El Servicio de mantenimiento asignado no existe o está inactivo.');
        }

        $updated = PlanMantenimiento::reconfigurar(
            (int) $plan->id(),
            $plan->empresaId(),
            $plan->equipoId(),
            $plan->tipoServicioId(),
            $definition['intervalKm'],
            $definition['intervalHoursTenths'],
            $definition['intervalDays'],
            $definition['warningKm'],
            $definition['warningHoursTenths'],
            $definition['warningDays'],
            $plan->baseKm(),
            $plan->baseHorasDecimas(),
            $plan->baseFecha(),
            $definition['priority'],
            $plan->observaciones(),
        );

        $updated->recalcularDesdeCierre($completedAt, $outputKm, $outputHoursTenths);
        $this->plans->save($updated, $actorUserId);

        return [
            'proximo_km' => $updated->proximoKm(),
            'proximas_horas_decimas' => $updated->proximasHorasDecimas(),
            'proxima_fecha' => $updated->proximaFecha()?->format('Y-m-d'),
        ];
    }
}
