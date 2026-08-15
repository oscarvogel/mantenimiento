<?php

declare(strict_types=1);

namespace App\Application\PreventiveMaintenance\Port;

interface ActiveCompanyCatalog
{
    /** @return list<int> */
    public function listActiveCompanyIds(): array;
}
