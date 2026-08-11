<?php

declare(strict_types=1);

namespace App\Application\PreventiveMaintenance\Port;

use App\Domain\PreventiveMaintenance\PlantillaPreventiva;

interface PreventiveTemplateGateway
{
    /** @return list<PlantillaPreventiva> */
    public function listActiveCandidates(int $companyId): array;
}
