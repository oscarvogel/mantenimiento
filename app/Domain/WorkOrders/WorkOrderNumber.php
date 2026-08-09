<?php

declare(strict_types=1);

namespace App\Domain\WorkOrders;

use DomainException;

final readonly class WorkOrderNumber
{
    private function __construct(
        private int $year,
        private int $sequence,
    ) {
    }

    public static function fromSequence(int $year, int $sequence): self
    {
        if ($year < 2000 || $year > 9999) {
            throw new DomainException('El aÃ±o de la numeraciÃ³n de OT no es vÃ¡lido.');
        }
        if ($sequence < 1 || $sequence > 999999) {
            throw new DomainException('La secuencia de OT debe estar entre 1 y 999999.');
        }

        return new self($year, $sequence);
    }

    public static function fromString(string $number): self
    {
        if (preg_match('/^OT-(\d{4})-(\d{6})$/', $number, $matches) !== 1) {
            throw new DomainException('El nÃºmero de OT no tiene el formato esperado.');
        }

        return self::fromSequence((int) $matches[1], (int) $matches[2]);
    }

    public function value(): string
    {
        return sprintf('OT-%04d-%06d', $this->year, $this->sequence);
    }

    public function year(): int
    {
        return $this->year;
    }

    public function sequence(): int
    {
        return $this->sequence;
    }
}
