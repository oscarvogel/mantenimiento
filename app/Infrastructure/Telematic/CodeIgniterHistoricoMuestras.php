<?php

declare(strict_types=1);

namespace App\Infrastructure\Telematic;

use App\Application\Telematic\Port\HistoricoMuestras;
use App\Domain\Telematic\MuestraEquipo;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;

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
final class CodeIgniterHistoricoMuestras implements HistoricoMuestras
{
    private const TABLA = 'telematia_muestras';

    private readonly BaseConnection $mysql;

    public function __construct(?BaseConnection $mysql = null)
    {
        $this->mysql = $mysql ?? Database::connect();
    }

    public function anterioresA(int $empresaId, DateTimeImmutable $corte): array
    {
        if ($empresaId <= 0 || ! $this->hasTable()) {
            return [];
        }

        $filas = $this->mysql->table(self::TABLA)
            ->where('empresa_id', $empresaId)
            ->where('observada_en <', $corte->format('Y-m-d H:i:s'))
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
            ->where('observada_en <', $corte->format('Y-m-d H:i:s'))
            ->delete();

        return $ok ? (int) $this->mysql->affectedRows() : 0;
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