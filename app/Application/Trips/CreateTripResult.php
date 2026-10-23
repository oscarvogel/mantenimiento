<?php

declare(strict_types=1);

namespace App\Application\Trips;

final readonly class CreateTripResult
{
    public function __construct(
        public int $id,
        public int $number,
    ) {
    }
}
