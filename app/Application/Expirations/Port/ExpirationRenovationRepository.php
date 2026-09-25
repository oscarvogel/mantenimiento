<?php

declare(strict_types=1);

namespace App\Application\Expirations\Port;

use App\Domain\Expirations\ExpirationRenovation;
use DateTimeImmutable;

interface ExpirationRenovationRepository
{
    public function add(ExpirationRenovation $renovation): int;

    /**
     * @return list<ExpirationRenovation>
     */
    public function listForExpiration(int $companyId, int $expirationId): array;
}
