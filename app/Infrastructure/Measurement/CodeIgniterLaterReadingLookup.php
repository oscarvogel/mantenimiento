<?php

declare(strict_types=1);

namespace App\Infrastructure\Measurement;

use App\Application\Measurement\Port\LaterReadingLookup;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;

final class CodeIgniterLaterReadingLookup implements LaterReadingLookup
{
    public function __construct(private readonly BaseConnection $database)
    {
    }

    public function hasLaterReadingAtOrAbove(int $companyId, int $equipmentId, DateTimeImmutable $after, int $kilometers): bool
    {
        return $this->database->table('lecturas_equipo')
            ->where('empresa_id', $companyId)
            ->where('equipo_id', $equipmentId)
            ->where('anulada', 0)
            ->where('fecha_lectura >', $after->format('Y-m-d H:i:s'))
            ->where('kilometraje >=', $kilometers)
            ->countAllResults() > 0;
    }
}
