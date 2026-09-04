<?php

declare(strict_types=1);

namespace App\Application\Measurement;

use DateTimeImmutable;

final readonly class ReadingHistoryItem
{
    public function __construct(
        public int $id,
        public int $equipmentId,
        public int $branchId,
        public DateTimeImmutable $recordedAt,
        public ?int $kilometers,
        public ?string $hours,
        public string $origin,
        public ?int $userId,
        public ?string $userName,
        public ?string $notes,
        public ?string $correctionReason,
        public ?int $correctedReadingId,
        public ?int $replacementReadingId,
        public bool $annulled,
        public ?DateTimeImmutable $annulledAt,
        public ?int $annulledBy,
        public ?string $annulledByName,
        public ?string $annulmentReason,
    ) {
    }
}
