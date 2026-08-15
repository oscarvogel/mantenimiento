<?php

declare(strict_types=1);

namespace App\Application\PreventiveMaintenance;

use App\Application\PreventiveMaintenance\Port\ActiveCompanyCatalog;
use App\Application\PreventiveMaintenance\Port\Clock;
use App\Domain\PreventiveMaintenance\EstadoPlan;

final readonly class DetectOverduePlansAutomatically
{
    public function __construct(
        private ActiveCompanyCatalog $companies,
        private ConsultarVencimientos $query,
        private MaterializarAvisoVencido $materialize,
        private Clock $clock,
    ) {
    }

    /** @return array{companies: int, evaluated: int, overdue: int, notices: list<int>} */
    public function execute(): array
    {
        $evaluated = 0;
        $overdue = 0;
        $notices = [];
        $now = $this->clock->now();

        $companyIds = $this->companies->listActiveCompanyIds();
        foreach ($companyIds as $companyId) {
            foreach ($this->query->executeScoped($companyId) as $result) {
                ++$evaluated;
                if ($result['evaluation']->estado() !== EstadoPlan::VENCIDO) {
                    continue;
                }

                ++$overdue;
                $notices[] = $this->materialize->execute(
                    $result['plan'],
                    $result['evaluation'],
                    $now,
                );
            }
        }

        return [
            'companies' => count($companyIds),
            'evaluated' => $evaluated,
            'overdue' => $overdue,
            'notices' => $notices,
        ];
    }
}
