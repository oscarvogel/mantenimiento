<?php

declare(strict_types=1);

namespace App\Application\Measurement;

final readonly class CorrectReadingResult
{
    public function __construct(
        public int $originalReadingId,
        public int $correctionReadingId,
        public int $equipmentId,
        public int $companyId,
        public int $branchId,
        public ?int $currentKilometers,
        public ?string $currentHours,
    ) {
    }
}
