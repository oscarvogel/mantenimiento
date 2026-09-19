<?php

declare(strict_types=1);

namespace App\Application\Expirations\Port;

use App\Domain\Expirations\ExpirationEvidence;
use DateTimeImmutable;

interface ExpirationEvidenceRepository
{
    public function add(ExpirationEvidence $evidence): int;

    public function findForCompany(int $companyId, int $evidenceId): ?ExpirationEvidence;
}
