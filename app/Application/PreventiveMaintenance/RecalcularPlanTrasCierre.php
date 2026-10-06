<?php

declare(strict_types=1);

namespace App\Application\PreventiveMaintenance;

use App\Application\PreventiveMaintenance\Port\MaintenanceNoticeRepository;
use App\Application\PreventiveMaintenance\Port\PlanMantenimientoRepository;
use App\Application\PreventiveMaintenance\Port\ServiceTypeGateway;
use App\Domain\PreventiveMaintenance\AvisoPlan;
use App\Domain\PreventiveMaintenance\PlanMantenimiento;
use DateTimeImmutable;
use DomainException;

final readonly class RecalcularPlanTrasCierre
{
    public function __construct(
        private PlanMantenimientoRepository $plans,
        private ServiceTypeGateway $services,
        private MaintenanceNoticeRepository $notices,
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

        $previousCycleKey = AvisoPlan::claveCicloPara($plan);

        $updated->recalcularDesdeCierre($completedAt, $outputKm, $outputHoursTenths);
        $this->plans->save($updated, $actorUserId);

        $this->resolveSupersededNotices($companyId, (int) $plan->id(), $previousCycleKey, $completedAt, $actorUserId);

        return [
            'proximo_km' => $updated->proximoKm(),
            'proximas_horas_decimas' => $updated->proximasHorasDecimas(),
            'proxima_fecha' => $updated->proximaFecha()?->format('Y-m-d'),
        ];
    }

    /**
     * El cierre abre un ciclo nuevo para el plan, asi que los avisos PENDIENTE
     * del ciclo que acaba de cerrarse quedaron obsoletos: sin resolverlos
     * seguirian apareciendo como vencidos aunque el plan recien recalculado ya
     * este al dia o proximo.
     *
     * Solo se resuelve el ciclo exacto que el cierre supero (claveCiclo), no
     * todos los avisos del plan. Un aviso de otro ciclo, o uno recien detectado
     * para el ciclo nuevo, sigue siendo una accion pendiente legitima y debe
     * sobrevivir a este cierre.
     *
     * Los avisos CONVERTIDO no se tocan:Convertido significa que ya se genero
     * una OT para ese aviso, y la conversion ya escribio su fecha_resolucion.
     */
    private function resolveSupersededNotices(
        int $companyId,
        int $planId,
        string $previousCycleKey,
        DateTimeImmutable $at,
        int $actorUserId,
    ): void {
        foreach ($this->notices->pendingForCycle($companyId, $planId, $previousCycleKey) as $notice) {
            $notice->marcarResuelto($at, 'Ciclo superado: la orden se genero desde el plan y no desde este aviso.');
            $this->notices->save($notice, $actorUserId);
        }
    }
}
