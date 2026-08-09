<?php

declare(strict_types=1);

namespace App\Application\Measurement;

use DateTimeImmutable;

final readonly class RegisterReadingCommand
{
    public function __construct(
        public int $equipmentId,
        public DateTimeImmutable $recordedAt,
        public ?int $kilometers,
        public int|float|string|null $hours,
        public string $origin,
        public ?string $originReference = null,
        public ?string $correctionReason = null,
        public ?string $notes = null,
    ) {
    }
}
