<?php

declare(strict_types=1);

namespace App\Application\Measurement;

use DateTimeImmutable;
use DomainException;

final readonly class RegisterReadingBatchItem
{
    public function __construct(
        public int $rowNumber,
        public int $equipmentId,
        public DateTimeImmutable $recordedAt,
        public ?int $kilometers,
        public int|float|string|null $hours,
        public ?string $notes = null,
    ) {
        if ($rowNumber <= 0 || $equipmentId <= 0) {
            throw new DomainException('La fila de lectura rápida no es válida.');
        }
    }
}
