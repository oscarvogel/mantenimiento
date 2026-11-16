<?php

declare(strict_types=1);

namespace App\Domain\Measurement;

final class AiOdometerPlausibility
{
    public function accepts(?int $detectedKm, ?int $currentKm): bool
    {
        if ($detectedKm === null || $currentKm === null) {
            return true;
        }

        return $detectedKm >= $currentKm;
    }
}
