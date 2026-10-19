<?php

declare(strict_types=1);

namespace App\Application\PreventiveMaintenance;

use App\Application\PreventiveMaintenance\Port\ActiveCompanyCatalog;
use App\Application\PreventiveMaintenance\Port\Clock;
use App\Domain\PreventiveMaintenance\EstadoPlan;
use Throwable;

final readonly class DetectOverduePlansAutomatically
{
    public function __construct(
        private ActiveCompanyCatalog $companies,
        private ConsultarVencimientos $query,
        private MaterializarAvisoVencido $materialize,
        private Clock $clock,
    ) {
    }

    /** @return array{companies: int, evaluated: int, overdue: int, failed: int, notices: list<int>} */
    public function execute(): array
    {
        $evaluated = 0;
        $overdue = 0;
        $failed = 0;
        $notices = [];
        $now = $this->clock->now();

        $companyIds = $this->companies->listActiveCompanyIds();
        foreach ($companyIds as $companyId) {
            // Una empresa con un plan incoherente no puede abortar el ciclo de
            // notificaciones entero (#167). Si la evaluación scoped falla, se
            // omite esa empresa y se registra para revisión; el resto del lote
            // y el despacho siguen.
            try {
                $results = $this->query->executeScoped($companyId);
            } catch (Throwable $error) {
                ++$failed;
                log_message('error', 'Se omitió la evaluación de vencimientos de la empresa {company}: {message}', [
                    'company' => $companyId,
                    'message' => $error->getMessage(),
                ]);
                continue;
            }

            foreach ($results as $result) {
                ++$evaluated;
                if ($result['evaluation']->estado() !== EstadoPlan::VENCIDO) {
                    continue;
                }

                ++$overdue;
                try {
                    $notices[] = $this->materialize->execute(
                        $result['plan'],
                        $result['evaluation'],
                        $now,
                    );
                } catch (Throwable $error) {
                    ++$failed;
                    log_message('error', 'No se pudo materializar el aviso del plan {plan} de la empresa {company}: {message}', [
                        'plan' => $result['plan']->id(),
                        'company' => $companyId,
                        'message' => $error->getMessage(),
                    ]);
                }
            }
        }

        return [
            'companies' => count($companyIds),
            'evaluated' => $evaluated,
            'overdue' => $overdue,
            'failed' => $failed,
            'notices' => $notices,
        ];
    }
}
