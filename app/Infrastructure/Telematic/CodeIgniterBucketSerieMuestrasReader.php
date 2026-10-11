<?php

declare(strict_types=1);

namespace App\Infrastructure\Telematic;

use App\Application\Telematic\Port\SerieMuestrasReader;
use App\Domain\Telematic\InstantaneaEquipo;
use App\Domain\Telematic\MuestraEquipo;
use App\Domain\Telematic\Posicion;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;
use DateTimeZone;

/** Adapta los extremos persistidos de buckets a la serie histórica del dominio. */
final class CodeIgniterBucketSerieMuestrasReader implements SerieMuestrasReader
{
    private const TABLA = 'telematia_buckets';

    private readonly BaseConnection $mysql;

    public function __construct(?BaseConnection $mysql = null)
    {
        $this->mysql = $mysql ?? Database::connect();
    }

    public function serie(int $empresaId, int $equipoId, DateTimeImmutable $desde, DateTimeImmutable $hasta): array
    {
        if ($empresaId <= 0 || $equipoId <= 0 || $hasta < $desde || ! $this->mysql->tableExists(self::TABLA)) {
            return [];
        }

        $desdeUtc = $desde->setTimezone(new DateTimeZone('UTC'));
        $hastaUtc = $hasta->setTimezone(new DateTimeZone('UTC'));
        $filas = $this->mysql->table(self::TABLA)
            ->select([
                'empresa_id', 'integracion_id', 'equipo_id', 'unidad_externa', 'proveedor', 'created_at',
                'primera_observada_en', 'ultima_observada_en',
                'combustible_inicio_litros', 'combustible_fin_litros',
                'kilometraje_inicio', 'kilometraje_fin',
                'horas_decimales_inicio', 'horas_decimales_fin',
                'motor_inicio', 'motor_fin', 'ralenti_inicio', 'ralenti_fin',
                'latitud_inicio', 'longitud_inicio', 'velocidad_inicio_kmh', 'rumbo_inicio', 'altitud_inicio_m', 'satelites_inicio',
                'latitud_fin', 'longitud_fin', 'velocidad_fin_kmh', 'rumbo_fin', 'altitud_fin_m', 'satelites_fin',
            ])
            ->where('empresa_id', $empresaId)
            ->where('equipo_id', $equipoId)
            ->where('primera_observada_en <=', $hastaUtc->format('Y-m-d H:i:s'))
            ->where('ultima_observada_en >=', $desdeUtc->format('Y-m-d H:i:s'))
            ->orderBy('primera_observada_en', 'ASC')
            ->orderBy('integracion_id', 'ASC')
            ->get()
            ->getResultArray();

        $muestras = [];
        $indice = 0;
        foreach ($filas as $fila) {
            $primera = $this->fecha((string) $fila['primera_observada_en']);
            $ultima = $this->fecha((string) $fila['ultima_observada_en']);
            $registrada = $this->fecha((string) ($fila['created_at'] ?? $fila['ultima_observada_en']));

            if ($primera >= $desdeUtc && $primera <= $hastaUtc) {
                $muestras[] = ['muestra' => $this->muestra($fila, 'inicio', $primera, $registrada), 'indice' => $indice++];
            }

            if ($ultima != $primera && $ultima >= $desdeUtc && $ultima <= $hastaUtc) {
                $muestras[] = ['muestra' => $this->muestra($fila, 'fin', $ultima, $registrada), 'indice' => $indice++];
            }
        }

        usort($muestras, static function (array $a, array $b): int {
            $porFecha = $a['muestra']->observadaEn() <=> $b['muestra']->observadaEn();

            return $porFecha !== 0 ? $porFecha : ($a['indice'] <=> $b['indice']);
        });

        return array_map(static fn (array $item): MuestraEquipo => $item['muestra'], $muestras);
    }

    /** @param array<string,mixed> $fila */
    private function muestra(array $fila, string $extremo, DateTimeImmutable $observada, DateTimeImmutable $registrada): MuestraEquipo
    {
        $sufijo = $extremo === 'inicio' ? '_inicio' : '_fin';
        $latitud = $fila['latitud' . $sufijo] ?? null;
        $longitud = $fila['longitud' . $sufijo] ?? null;
        $posicion = $latitud === null || $longitud === null
            ? null
            : new Posicion(
                (float) $latitud,
                (float) $longitud,
                $this->flotante($fila['velocidad' . $sufijo . '_kmh'] ?? null),
                $this->entero($fila['rumbo' . $sufijo] ?? null),
                $this->flotante($fila['altitud' . $sufijo . '_m'] ?? null),
                $this->entero($fila['satelites' . $sufijo] ?? null),
                $observada,
            );

        $instantanea = new InstantaneaEquipo(
            $observada,
            $posicion,
            $this->entero($fila['kilometraje' . $sufijo] ?? null),
            $this->entero($fila['horas_decimales' . $sufijo] ?? null),
            $this->booleano($fila['motor' . $sufijo] ?? null),
            $this->booleano($fila['ralenti' . $sufijo] ?? null),
            null,
            $this->flotante($fila['combustible' . $sufijo . '_litros'] ?? null),
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

    private function fecha(string $valor): DateTimeImmutable
    {
        return (new DateTimeImmutable($valor, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));
    }

    private function flotante(mixed $valor): ?float
    {
        return $valor === null ? null : (float) $valor;
    }

    private function entero(mixed $valor): ?int
    {
        return $valor === null ? null : (int) $valor;
    }

    private function booleano(mixed $valor): ?bool
    {
        return $valor === null ? null : ((int) $valor === 1);
    }
}
