<?php

declare(strict_types=1);

namespace App\Infrastructure\Measurement;

use App\Application\PreventiveMaintenance\Port\LecturaKmVigenteReader;
use App\Domain\PreventiveMaintenance\Gps\LecturaKmVigente;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Adaptador de Medición para el contrato `LecturaKmVigenteReader` de Preventivo.
 *
 * La lectura vigente es la más reciente no anulada cuyo km es el odómetro
 * actual del equipo. Así una lectura histórica de viaje, que se guarda con km
 * menor y no mueve el odómetro, no se toma como base. Las dos tablas se
 * recortan por empresa.
 */
final readonly class CodeIgniterLecturaKmVigenteReader implements LecturaKmVigenteReader
{
    public function __construct(
        private BaseConnection $db,
        private DateTimeZone $zona = new DateTimeZone('America/Argentina/Buenos_Aires'),
    ) {
    }

    public function vigentes(int $empresaId, array $equipoIds): array
    {
        $equipoIds = array_values(array_unique(array_filter(array_map('intval', $equipoIds), static fn (int $id): bool => $id > 0)));
        if ($empresaId <= 0 || $equipoIds === []) {
            return [];
        }

        $filas = $this->db->table('lecturas_equipo l')
            ->select('l.equipo_id, l.kilometraje, MAX(l.fecha_lectura) AS fecha_lectura', false)
            ->join('equipos e', 'e.id = l.equipo_id AND e.empresa_id = l.empresa_id AND e.km_actual = l.kilometraje', 'inner')
            ->where('l.empresa_id', $empresaId)
            ->where('e.empresa_id', $empresaId)
            ->where('l.anulada', 0)
            ->where('l.kilometraje IS NOT NULL', null, false)
            ->whereIn('l.equipo_id', $equipoIds)
            ->groupBy(['l.equipo_id', 'l.kilometraje'])
            ->get()
            ->getResultArray();

        $vigentes = [];
        foreach ($filas as $fila) {
            $equipoId = (int) $fila['equipo_id'];
            $vigentes[$equipoId] = new LecturaKmVigente(
                $equipoId,
                (int) $fila['kilometraje'],
                new DateTimeImmutable((string) $fila['fecha_lectura'], $this->zona),
            );
        }

        return $vigentes;
    }
}
