<?php

declare(strict_types=1);

namespace App\Domain\WorkOrders;

use DateTimeImmutable;
use DomainException;

final readonly class WorkOrderClosure
{
    public function __construct(
        private DateTimeImmutable $completedAt,
        private ?int $outputKilometres,
        private ?string $outputHours,
    ) {
        if ($outputKilometres !== null && $outputKilometres < 0) {
            throw new DomainException('El kilometraje de salida no puede ser negativo.');
        }
        if ($outputHours !== null && (! is_numeric($outputHours) || (float) $outputHours < 0)) {
            throw new DomainException('El horÃ³metro de salida no es vÃ¡lido.');
        }
    }

    public function completedAt(): DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function outputKilometres(): ?int
    {
        return $this->outputKilometres;
    }

    public function outputHours(): ?string
    {
        return $this->outputHours === null ? null : number_format((float) $this->outputHours, 1, '.', '');
    }
}
