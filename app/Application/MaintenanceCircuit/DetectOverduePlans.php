<?php

declare(strict_types=1);

namespace App\Application\MaintenanceCircuit;

use App\Application\Identity\ActorContext;
use App\Application\PreventiveMaintenance\ConsultarVencimientos;
use App\Application\PreventiveMaintenance\MaterializarAvisoVencido;
use App\Application\PreventiveMaintenance\Port\Clock;
use App\Domain\PreventiveMaintenance\EstadoPlan;
use DomainException;

final readonly class DetectOverduePlans
{
    public function __construct(
        private ConsultarVencimientos $query,
        private MaterializarAvisoVencido $materialize,
        private Clock $clock,
    ) {
    }

    /** @return array{evaluated: int, overdue: int, notices: list<int>} */
    public function execute(ActorContext $actor): array
    {
        if ($actor->isSuperAdmin() || $actor->companyId() === null) {
            throw new DomainException('La detección requiere una cuenta perteneciente a una empresa.');
        }
        if (! $actor->hasPermission('planes.editar')) {
            throw new DomainException('No tenés permiso para materializar avisos de mantenimiento.');
        }

        $evaluations = $this->query->execute($actor, $actor->companyId());
        $notices = [];
        $overdue = 0;
        $now = $this->clock->now();

        foreach ($evaluations as $result) {
            if ($result['evaluation']->estado() !== EstadoPlan::VENCIDO) {
                continue;
            }
            ++$overdue;
            $notices[] = $this->materialize->execute(
                $result['plan'],
                $result['evaluation'],
                $now,
                $actor->userId(),
            );
        }

        return ['evaluated' => count($evaluations), 'overdue' => $overdue, 'notices' => $notices];
    }
}
