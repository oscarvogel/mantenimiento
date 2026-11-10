<?php

declare(strict_types=1);

namespace App\Application\Trips\Port;

interface TripLocalityCreator
{
    /** @return array{id:int,created:bool} */
    public function findOrCreate(int $provinceId, string $name): array;
}
