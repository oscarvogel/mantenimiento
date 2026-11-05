<?php

declare(strict_types=1);

namespace App\Domain\Measurement;

final class AiDiscrepancyPolicy
{
    public const MIN_CONFIDENCE = 0.80;
    public const MIN_KILOMETERS = 1000;
    public const MIN_RATIO = 0.05;

    public function needsReview(?int $detectedKm, ?int $confirmedKm, ?float $confidence): bool
    {
        if ($detectedKm === null || $confirmedKm === null || $confidence === null) {
            return false;
        }
        if ($confidence < self::MIN_CONFIDENCE) {
            return false;
        }

        $difference = abs($detectedKm - $confirmedKm);
        $ratio = $detectedKm > 0 ? $difference / $detectedKm : 0.0;

        return $difference >= self::MIN_KILOMETERS && $ratio >= self::MIN_RATIO;
    }
}
