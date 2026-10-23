<?php

declare(strict_types=1);

namespace App\Domain\Trips;

final readonly class TripSettlement
{
    public function __construct(
        private float $commission,
        private float $otherEarnings,
        private float $driverExpenses,
        private float $cashExpenses,
        private float $advances,
        private float $returns,
    ) {
    }

    public function commission(): float
    {
        return round($this->commission, 2);
    }

    public function otherEarnings(): float
    {
        return round($this->otherEarnings, 2);
    }

    public function driverExpenses(): float
    {
        return round($this->driverExpenses, 2);
    }

    public function cashExpenses(): float
    {
        return round($this->cashExpenses, 2);
    }

    public function advances(): float
    {
        return round($this->advances, 2);
    }

    public function returns(): float
    {
        return round($this->returns, 2);
    }

    public function accountBalance(): float
    {
        return round(
            $this->driverExpenses + $this->cashExpenses + $this->returns - $this->advances,
            2,
        );
    }

    public function total(): float
    {
        return round($this->commission + $this->otherEarnings + $this->accountBalance(), 2);
    }
}
