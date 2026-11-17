<?php

declare(strict_types=1);

namespace App\Application\Measurement;

use DateTimeImmutable;

final readonly class ReadingEvidenceReviewTarget
{
    public function __construct(
        public int $evidenceId,
        public int $readingId,
        public int $equipmentId,
        public int $branchId,
        public string $equipmentCode,
        public ?string $method,
        public ?int $aiDetectedKm,
        public ?int $confirmedKm,
        public ?DateTimeImmutable $reviewedAt = null,
        public ?string $reviewedByName = null,
        public ?string $reviewReason = null,
        public ?string $reviewObservation = null,
    ) {
    }

    public function hasAiDiscrepancy(): bool
    {
        return $this->method === 'FOTO_IA_CORREGIDA'
            && $this->aiDetectedKm !== null
            && $this->confirmedKm !== null
            && $this->aiDetectedKm !== $this->confirmedKm;
    }
}
