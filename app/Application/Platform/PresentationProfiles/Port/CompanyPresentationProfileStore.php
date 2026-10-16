<?php

declare(strict_types=1);

namespace App\Application\Platform\PresentationProfiles\Port;

interface CompanyPresentationProfileStore
{
    public function assign(int $companyId, string $profileKey, int $actorUserId, bool $exclusive): void;
}
