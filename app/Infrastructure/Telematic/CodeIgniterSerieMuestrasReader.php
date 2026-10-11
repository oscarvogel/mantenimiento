<?php

declare(strict_types=1);

namespace App\Infrastructure\Telematic;

use App\Application\Telematic\Port\SerieMuestrasReader;
use App\Domain\Telematic\MuestraEquipo;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;

/**
 * Lee la serie de capturas de un equipo en una ventana temporal.
 *
 * El `empresa_id` va siempre en el `WHERE` y no es opcional: es la invariante de
 * seguridad del sistema, y estas filas tienen coordenadas. Una consulta de
 * histórico sin empresa devolvería datos de otro cliente.
 *
 * El orden lo pone la base con `ORDER BY observada_en`, y no el proceso. Ordenar
 * acá significaría traer la serie entera, ordenarla en memoria y devolverla: la
 * misma operación con más memoria y sin que la base pueda usar el índice.
 */
final class CodeIgniterSerieMuestrasReader implements SerieMuestrasReader
{
    private const TABLA = 'telematia_muestras';

    private readonly BaseConnection $mysql;

    public function __construct(?BaseConnection $mysql = null)
    {
        $this->mysql = $mysql ?? Database::connect();
    }

    public function serie(
        int $empresaId,
        int $equipoId,
        DateTimeImmutable $desde,
        DateTimeImmutable $hasta,
    ): array {
        if ($empresaId <= 0 || $equipoId <= 0) {
            return [];
        }

        if ($this->mysql->tableExists(self::TABLA) === false) {
            return [];
        }

        $filas = $this->mysql->table(self::TABLA)
            ->where('empresa_id', $empresaId)
            ->where('equipo_id', $equipoId)
            ->where('observada_en >=', $desde->format('Y-m-d H:i:s'))
            ->where('observada_en <=', $hasta->format('Y-m-d H:i:s'))
            ->orderBy('observada_en', 'ASC')
            ->get()
            ->getResultArray();

        $mapeo = new FilaAMuestra();

        return array_map($mapeo, $filas);
    }
}