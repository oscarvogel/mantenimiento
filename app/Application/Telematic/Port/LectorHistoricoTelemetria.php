<?php

declare(strict_types=1);

namespace App\Application\Telematic\Port;

use App\Domain\Telematic\MensajeHistorico;
use DateTimeImmutable;

/** Lee mensajes históricos normalizados para una unidad vinculada a la empresa. */
interface LectorHistoricoTelemetria
{
    /** @return list<MensajeHistorico> Mensajes completos, ordenados por hora de observación. */
    public function mensajesEntre(
        int $integracionId,
        string $unidadExterna,
        DateTimeImmutable $desde,
        DateTimeImmutable $hasta,
    ): array;
}
