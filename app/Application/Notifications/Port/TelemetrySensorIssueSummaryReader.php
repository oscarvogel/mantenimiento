<?php

declare(strict_types=1);

namespace App\Application\Notifications\Port;

interface TelemetrySensorIssueSummaryReader
{
    /**
     * @return array{count:int,issues:list<array{equipmentCode:string,summary:string}>}
     */
    public function forCompany(int $companyId, int $limit = 10): array;
}
