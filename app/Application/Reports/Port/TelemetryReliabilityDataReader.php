<?php

declare(strict_types=1);

namespace App\Application\Reports\Port;

use App\Application\Reports\ReportScope;

interface TelemetryReliabilityDataReader
{
    /**
     * @return array{
     *   equipment:list<array{id:int,code:string,plate:?string,branchName:?string}>,
     *   readings:list<array{equipmentId:int,at:\DateTimeImmutable,km:int}>,
     *   samples:list<array{equipmentId:int,integrationId:int,integrationName:string,provider:string,at:\DateTimeImmutable,km:int}>,
     *   branches:list<array{id:int,nombre:string}>
     * }
     */
    public function fetch(ReportScope $scope): array;
}
