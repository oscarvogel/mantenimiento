<?php

declare(strict_types=1);

namespace App\Application\Measurement;

use DateTimeImmutable;

final readonly class CorrectReadingCommand
{
    public function __construct(
        public int $equipmentId,
        public int $readingId,
        public ?int $kilometers,
        public int|float|string|null $hours,
        public string $reason,
        public DateTimeImmutable $correctedAt,
        public ?string $notes = null,
    ) {
    }
}
