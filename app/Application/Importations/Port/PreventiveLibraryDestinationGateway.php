<?php

declare(strict_types=1);

namespace App\Application\Importations\Port;

interface PreventiveLibraryDestinationGateway
{
    /** @param array<string,mixed> $data */
    public function apply(int $companyId, int $actorUserId, array $data): int;
}
