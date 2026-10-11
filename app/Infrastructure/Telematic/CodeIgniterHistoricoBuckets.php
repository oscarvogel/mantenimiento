<?php

declare(strict_types=1);

namespace App\Infrastructure\Telematic;

use App\Application\Telematic\BucketHistorico;
use App\Application\Telematic\Port\HistoricoBuckets;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;

/** Consulta y purga buckets completamente anteriores a la fecha de retención. */
final class CodeIgniterHistoricoBuckets implements HistoricoBuckets
{
    private const TABLA = 'telematia_buckets';

    private readonly BaseConnection $mysql;

    public function __construct(?BaseConnection $mysql = null)
    {
        $this->mysql = $mysql ?? Database::connect();
    }

    public function anterioresA(int $empresaId, DateTimeImmutable $corte): array
    {
        if ($empresaId <= 0 || ! $this->mysql->tableExists(self::TABLA)) {
            return [];
        }

        $filas = $this->mysql->table(self::TABLA)
            ->where('empresa_id', $empresaId)
            ->where('ultima_observada_en <', $corte->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'))
            ->orderBy('bucket_inicio_utc', 'ASC')
            ->orderBy('integracion_id', 'ASC')
            ->get()
            ->getResultArray();

        return array_map(static fn (array $fila): BucketHistorico => new BucketHistorico($fila), $filas);
    }

    public function borrarAnterioresA(int $empresaId, DateTimeImmutable $corte): int
    {
        if ($empresaId <= 0 || ! $this->mysql->tableExists(self::TABLA)) {
            return 0;
        }

        $ok = $this->mysql->table(self::TABLA)
            ->where('empresa_id', $empresaId)
            ->where('ultima_observada_en <', $corte->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'))
            ->delete();

        return $ok ? (int) $this->mysql->affectedRows() : 0;
    }
}
