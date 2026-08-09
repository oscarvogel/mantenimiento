<?php

declare(strict_types=1);

namespace App\Domain\PreventiveMaintenance;

use InvalidArgumentException;

final readonly class UsoActual
{
    public function __construct(
        private ?int $kilometraje,
        private ?int $horasDecimas,
    ) {
        if ($kilometraje !== null && $kilometraje < 0) {
            throw new InvalidArgumentException('El kilometraje actual no puede ser negativo.');
        }

        if ($horasDecimas !== null && $horasDecimas < 0) {
            throw new InvalidArgumentException('El horometro actual no puede ser negativo.');
        }
    }

    public function kilometraje(): ?int
    {
        return $this->kilometraje;
    }

    public function horasDecimas(): ?int
    {
        return $this->horasDecimas;
    }
}
