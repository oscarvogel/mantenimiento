<?php

declare(strict_types=1);

namespace App\Application\Measurement\Port;

use App\Application\Measurement\ReadingEvidenceReviewTarget;
use App\Domain\Measurement\ReadingEvidenceReviewReason;
use DateTimeImmutable;

interface ReadingEvidenceReviewRepository
{
    /** @param list<int>|null $branchIds */
    public function findScoped(int $companyId, int $readingId, ?array $branchIds): ?ReadingEvidenceReviewTarget;

    public function save(
        int $companyId,
        ReadingEvidenceReviewTarget $target,
        ReadingEvidenceReviewReason $reason,
        ?string $observation,
        int $actorUserId,
        DateTimeImmutable $at,
    ): void;
}
