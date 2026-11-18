<?php

declare(strict_types=1);

namespace App\Application\Trips;

final readonly class FuelPriceEntry
{
    public function __construct(
        public int $fuelId,
        public float $amount,
        public string $currency = 'ARS',
        public float $exchangeRate = 1.0,
    ) {
    }
}
