<?php

declare(strict_types=1);

namespace App\Infrastructure\Telematic;

use App\Application\Telematic\Port\HistoricoMuestras;
use App\Application\Telematic\Port\SerieMuestrasReader;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Lectura y purga del histórico de muestras de una empresa.
 *
 * El corte usa `observada_en`, la hora del proveedor, que es la que ordena la
 * serie. Usar `registrada_en` mezclaría el tiempo del dispositivo con el nuestro
 * y dejaría huecos falsos en la ventana de retención.
 *
 * El mapeo de fila vive en `FilaAMuestra` y no acá: lo comparte con el lector
 * que arma la serie para el cálculo de consumo, y si divergieran el respaldo
 * contendría filas que el cálculo no puede reconstruir.
 */
final class CodeIgniterHistoricoMuestras implements HistoricoMuestras, SerieMuestrasReader
{
    private const TABLA = 'telematia_muestras';

    /**
     * Los DATETIME de `telematia_muestras` se guardan en la zona de la app;
     * a diferencia de los buckets, esa tabla no usa UTC.
     */
    private readonly DateTimeZone $zonaPersistida;

    private readonly BaseConnection $mysql;

    public function __construct(?BaseConnection $mysql = null)
    {
        $this->mysql = $mysql ?? Database::connect();
        $this->zonaPersistida = new DateTimeZone((new \Config\App())->appTimezone);
    }

    public function anterioresA(int $empresaId, DateTimeImmutable $corte): array
    {
        if ($empresaId <= 0 || ! $this->hasTable()) {
            return [];
        }

        $filas = $this->mysql->table(self::TABLA)
            ->where('empresa_id', $empresaId)
            ->where('observada_en <', $corte->setTimezone($this->zonaPersistida)->format('Y-m-d H:i:s'))
            ->orderBy('observada_en', 'ASC')
            ->get()
            ->getResultArray();

        $mapeo = new FilaAMuestra();

        return array_map($mapeo, $filas);
    }

    public function borrarAnterioresA(int $empresaId, DateTimeImmutable $corte): int
    {
        if ($empresaId <= 0 || ! $this->hasTable()) {
            return 0;
        }

        $ok = $this->mysql->table(self::TABLA)
            ->where('empresa_id', $empresaId)
            ->where('observada_en <', $corte->setTimezone($this->zonaPersistida)->format('Y-m-d H:i:s'))
            ->delete();

        return $ok ? (int) $this->mysql->affectedRows() : 0;
    }

    public function serie(int $empresaId, int $equipoId, DateTimeImmutable $desde, DateTimeImmutable $hasta): array
    {
        if ($empresaId <= 0 || $equipoId <= 0 || $hasta < $desde || ! $this->hasTable()) {
            return [];
        }

        $filas = $this->mysql->table(self::TABLA)
            ->where('empresa_id', $empresaId)
            ->where('equipo_id', $equipoId)
            ->where('observada_en >=', $desde->setTimezone($this->zonaPersistida)->format('Y-m-d H:i:s'))
            ->where('observada_en <=', $hasta->setTimezone($this->zonaPersistida)->format('Y-m-d H:i:s'))
            ->orderBy('observada_en', 'ASC')
            ->orderBy('integracion_id', 'ASC')
            ->get()
            ->getResultArray();

        $mapeo = new FilaAMuestra();

        return array_map($mapeo, $filas);
    }

    private function hasTable(): bool
    {
        static $existe = null;

        if ($existe === null) {
            $existe = $this->mysql->tableExists(self::TABLA);
        }

        return $existe;
    }
}
