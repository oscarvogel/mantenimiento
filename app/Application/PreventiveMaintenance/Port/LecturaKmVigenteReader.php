<?php

declare(strict_types=1);

namespace App\Application\PreventiveMaintenance\Port;

use App\Domain\PreventiveMaintenance\Gps\LecturaKmVigente;

/**
 * La lectura que define hoy el odómetro de cada equipo, con su fecha.
 *
 * Puerto del consumidor: lo usa Preventivo para anclar el GPS y lo responde
 * Medición, dueña de las lecturas. Es la última lectura no anulada cuyo km es
 * el odómetro actual del equipo; una lectura histórica de viaje (que no mueve
 * el odómetro) no cuenta.
 */
interface LecturaKmVigenteReader
{
    /**
     * @param int       $empresaId Obligatorio: cada consulta se recorta por empresa.
     * @param list<int> $equipoIds
     *
     * @return array<int, LecturaKmVigente> Por id de equipo; falta el equipo sin lectura vigente.
     */
    public function vigentes(int $empresaId, array $equipoIds): array;
}
