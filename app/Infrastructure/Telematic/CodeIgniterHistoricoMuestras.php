<?php

declare(strict_types=1);

namespace App\Infrastructure\Telematic;

use App\Application\Telematic\Port\HistoricoMuestras;
use App\Application\Telematic\Port\SerieMuestrasReader;
use App\Domain\Telematic\InstantaneaEquipo;
use App\Domain\Telematic\MuestraEquipo;
use App\Domain\Telematic\Posicion;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;

/**
 * Lectura y purga del histórico de muestras.
 *
 * El corte usa `observada_en`, la hora del proveedor, que es la que ordena la
 * serie. Usar `registrada_en` mezclaría el tiempo del dispositivo con el
 * nuestro y dejaría huecos falsos en la ventana de retención.
 */
final class CodeIgniterHistoricoMuestras implements HistoricoMuestras, SerieMuestrasReader
{
    private const TABLA = 'telematia_muestras';

    private readonly BaseConnection $mysql;

    public function __construct(?BaseConnection $mysql = null)
    {
        $this->mysql = $mysql ?? Database::connect();
    }

    public function anterioresA(int $empresaId, DateTimeImmutable $corte): array
    {
        if (! $this->hasTable()) {
            return [];
        }

        $filas = $this->mysql->table(self::TABLA)
            ->where('empresa_id', $empresaId)
            ->where('observada_en <', $corte->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'))
            ->orderBy('observada_en', 'ASC')
            ->get()
            ->getResultArray();

        return array_map(fn (array $fila): MuestraEquipo => $this->muestra($fila), $filas);
    }

    public function borrarAnterioresA(int $empresaId, DateTimeImmutable $corte): int
    {
        if (! $this->hasTable()) {
            return 0;
        }

        $ok = $this->mysql->table(self::TABLA)
            ->where('empresa_id', $empresaId)
            ->where('observada_en <', $corte->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'))
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
            ->where("LOWER(proveedor) != 'wialon'", null, false)
            ->where('observada_en >=', $desde->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'))
            ->where('observada_en <=', $hasta->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'))
            ->orderBy('observada_en', 'ASC')
            ->orderBy('integracion_id', 'ASC')
            ->get()
            ->getResultArray();

        return array_map(fn (array $fila): MuestraEquipo => $this->muestra($fila), $filas);
    }

    /** @param array<string,mixed> $fila */
    private function muestra(array $fila): MuestraEquipo
    {
        $utc = new \DateTimeZone('UTC');
        $observada = new DateTimeImmutable((string) $fila['observada_en'], $utc);
        $registrada = new DateTimeImmutable((string) ($fila['registrada_en'] ?? $fila['observada_en']), $utc);

        $posicion = $fila['latitud'] === null || $fila['longitud'] === null
            ? null
            : new Posicion(
                (float) $fila['latitud'],
                (float) $fila['longitud'],
                isset($fila['velocidad_kmh']) ? (float) $fila['velocidad_kmh'] : null,
                isset($fila['rumbo']) ? (int) $fila['rumbo'] : null,
                isset($fila['altitud_m']) ? (float) $fila['altitud_m'] : null,
                isset($fila['satelites']) ? (int) $fila['satelites'] : null,
                $observada,
            );

        $instantanea = new InstantaneaEquipo(
            $observada,
            $posicion,
            isset($fila['kilometraje']) ? (int) $fila['kilometraje'] : null,
            isset($fila['horas_decimales']) ? (int) $fila['horas_decimales'] : null,
            $this->booleano($fila['motor_encendido'] ?? null),
            $this->booleano($fila['ralenti_activo'] ?? null),
            isset($fila['voltaje']) ? (float) $fila['voltaje'] : null,
            isset($fila['combustible_litros']) ? (float) $fila['combustible_litros'] : null,
        );

        return new MuestraEquipo(
            (int) $fila['empresa_id'],
            (int) $fila['integracion_id'],
            (int) $fila['equipo_id'],
            (string) $fila['unidad_externa'],
            (string) $fila['proveedor'],
            $instantanea,
            $registrada,
        );
    }

    private function booleano(mixed $valor): ?bool
    {
        return $valor === null ? null : ((int) $valor === 1);
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
