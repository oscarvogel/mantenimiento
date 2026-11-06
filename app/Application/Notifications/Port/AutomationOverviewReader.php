<?php

declare(strict_types=1);

namespace App\Application\Notifications\Port;

interface AutomationOverviewReader
{
    /**
     * Evidencia de automatizaciones atribuible a una empresa y, opcionalmente,
     * a sus sucursales autorizadas. `null` significa todas las sucursales.
     *
     * @param list<int>|null $branchIds
     *
     * @return array{
     *     processes: list<array{key:string,label:string,description:string,enabled:?bool,schedule:?string,lastRunAt:?string,lastOutcome:?string,nextRunAt:?string,pending:int,failed:int,sent:int}>,
     *     deliveries: array<string,array{pending:int,failed:int,sent:int,lastActivityAt:?string}>,
     *     limitations: list<string>
     * }
     */
    public function forCompany(int $companyId, ?array $branchIds): array;
}
