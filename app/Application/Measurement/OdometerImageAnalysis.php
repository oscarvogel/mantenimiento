<?php

declare(strict_types=1);

namespace App\Application\Measurement;

final readonly class OdometerImageAnalysis
{
    public function __construct(
        public ?int $kilometers,
        public ?float $confidence,
        public bool $legible,
        public ?string $observation = null,
        public bool $evidenceValid = true,
        public ?string $invalidReason = null,
    ) {
    }
}
