<?php

declare(strict_types=1);

namespace App\Application\Notifications;

use App\Application\Identity\ActorContext;
use App\Application\Notifications\Port\AutomationOverviewReader;
use DomainException;

final readonly class GetAutomationOverview
{
    public function __construct(private AutomationOverviewReader $reader)
    {
    }

    /**
     * @return array{
     *     processes: list<array{key:string,label:string,description:string,enabled:?bool,schedule:?string,lastRunAt:?string,lastOutcome:?string,nextRunAt:?string,pending:int,failed:int,sent:int}>,
     *     deliveries: array<string,array{pending:int,failed:int,sent:int,lastActivityAt:?string}>,
     *     limitations: list<string>
     * }
     */
    public function execute(ActorContext $actor): array
    {
        if ($actor->isSuperAdmin() || $actor->companyId() === null) {
            throw new DomainException('Las automatizaciones requieren una cuenta perteneciente a una empresa.');
        }
        if (! $actor->hasPermission('automatizaciones.ver')) {
            throw new DomainException('No tenés permiso para consultar automatizaciones.');
        }

        $branchIds = $actor->hasAllCompanyBranches() ? null : $actor->branchIds();

        return $this->reader->forCompany($actor->companyId(), $branchIds);
    }
}
