<?php

declare(strict_types=1);

namespace App\Domain\Measurement;

enum ReadingEvidenceReviewReason: string
{
    case FALSE_POSITIVE = 'FALSO_POSITIVO_IA';
    case READING_CORRECTED = 'LECTURA_CORREGIDA';
    case REVIEW_MODEL = 'REVISAR_MODELO';
    case OTHER = 'OTRO';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $reason): string => $reason->value, self::cases());
    }

    public function requiresObservation(): bool
    {
        return $this === self::OTHER;
    }
}
