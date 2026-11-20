<?php

declare(strict_types=1);

namespace App\Application\Measurement\Port;

use DateTimeImmutable;

/**
 * Responde si un equipo ya tiene una lectura vigente (no anulada) posterior a
 * una fecha y con un kilometraje igual o mayor. Es lo que distingue una
 * lectura histórica de un retroceso del odómetro.
 */
interface LaterReadingLookup
{
    public function hasLaterReadingAtOrAbove(int $companyId, int $equipmentId, DateTimeImmutable $after, int $kilometers): bool;
}
