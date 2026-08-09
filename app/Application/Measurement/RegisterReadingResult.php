<?php

declare(strict_types=1);

namespace App\Application\Measurement;

final readonly class RegisterReadingResult
{
    public function __construct(
        public int $readingId,
        public int $equipmentId,
        public int $companyId,
        public int $branchId,
        public ?int $currentKilometers,
        public ?string $currentHours,
        public bool $correction,
    ) {
    }
}
