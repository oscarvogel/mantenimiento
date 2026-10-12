<?php

declare(strict_types=1);

namespace App\Infrastructure\Telematic;

use App\Application\Telematic\Port\TelemetryBucketStore;
use App\Domain\Telematic\BucketTelemetria;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Adaptador MySQL de buckets append-only y cursor transaccional por unidad. */
final class CodeIgniterTelemetryBucketStore implements TelemetryBucketStore
{
    private const BUCKETS = 'telematia_buckets';
    private const CURSOR = 'telematia_cursor';

    private readonly BaseConnection $mysql;

    public function __construct(?BaseConnection $mysql = null)
    {
        $this->mysql = $mysql ?? Database::connect();
    }

    public function cursor(int $empresaId, int $integracionId, string $unidadExterna): ?DateTimeImmutable
    {
        if ($empresaId <= 0 || $integracionId <= 0 || trim($unidadExterna) === ''
            || ! $this->mysql->tableExists(self::CURSOR)) {
            return null;
        }

        $row = $this->mysql->table(self::CURSOR)
            ->select('cursor_hasta_utc')
            ->where('empresa_id', $empresaId)
            ->where('integracion_id', $integracionId)
            ->where('unidad_externa', $unidadExterna)
            ->get()
            ->getRowArray();

        if ($row === null || empty($row['cursor_hasta_utc'])) {
            return null;
        }

        return new DateTimeImmutable((string) $row['cursor_hasta_utc'], new DateTimeZone('UTC'));
    }

    public function guardarCerradosYAvanzarCursor(
        int $empresaId,
        int $integracionId,
        int $equipoId,
        string $unidadExterna,
        string $proveedor,
        array $buckets,
        DateTimeImmutable $cursorHasta,
        DateTimeImmutable $registradaEn,
    ): array {
        if ($empresaId <= 0 || $integracionId <= 0 || $equipoId <= 0
            || trim($unidadExterna) === '' || trim($proveedor) === '') {
            throw new InvalidArgumentException('La persistencia de buckets necesita empresa, integración, equipo y unidad válidos.');
        }

        foreach ($buckets as $bucket) {
            if (! $bucket instanceof BucketTelemetria) {
                throw new InvalidArgumentException('Solo se pueden guardar buckets de telemetría.');
            }
        }

        if (! $this->mysql->tableExists(self::BUCKETS) || ! $this->mysql->tableExists(self::CURSOR)) {
            throw new RuntimeException('Faltan las tablas del histórico de telemetría por buckets.');
        }

        $registrada = $this->fechaUtc($registradaEn);
        $cursorSolicitado = $this->fechaUtc($cursorHasta);
        $this->mysql->transBegin();

        try {
            // El INSERT IGNORE obtiene la llave única del cursor antes de leerla
            // con FOR UPDATE. Dos cron concurrentes se serializan por unidad.
            $this->mysql->query(
                'INSERT IGNORE INTO ' . self::CURSOR . ' '
                . '(empresa_id, integracion_id, equipo_id, unidad_externa, proveedor, cursor_hasta_utc, created_at, updated_at) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$empresaId, $integracionId, $equipoId, $unidadExterna, $proveedor, $cursorSolicitado, $registrada, $registrada],
            );

            $cursorRow = $this->mysql->query(
                'SELECT id, cursor_hasta_utc FROM ' . self::CURSOR
                . ' WHERE empresa_id = ? AND integracion_id = ? AND unidad_externa = ? FOR UPDATE',
                [$empresaId, $integracionId, $unidadExterna],
            )->getRowArray();

            if ($cursorRow === null) {
                throw new RuntimeException('No se pudo bloquear el cursor de la unidad telemática.');
            }

            $cursorActual = new DateTimeImmutable((string) $cursorRow['cursor_hasta_utc'], new DateTimeZone('UTC'));
            $cursorFinal = $cursorSolicitado > $cursorActual ? $cursorSolicitado : $cursorActual;

            $insertados = 0;
            foreach ($buckets as $bucket) {
                $fila = $this->fila(
                    $empresaId,
                    $integracionId,
                    $equipoId,
                    $unidadExterna,
                    $proveedor,
                    $bucket,
                    $registrada,
                );
                $columnas = array_keys($fila);
                $marcadores = array_fill(0, count($columnas), '?');
                $this->mysql->query(
                    'INSERT IGNORE INTO ' . self::BUCKETS . ' (' . implode(', ', $columnas) . ') VALUES ('
                    . implode(', ', $marcadores) . ')',
                    array_values($fila),
                );
                $insertados += (int) $this->mysql->affectedRows();
            }

            $this->mysql->table(self::CURSOR)
                ->where('id', (int) $cursorRow['id'])
                ->where('empresa_id', $empresaId)
                ->update([
                    'empresa_id' => $empresaId,
                    'equipo_id' => $equipoId,
                    'proveedor' => $proveedor,
                    'cursor_hasta_utc' => $this->fechaUtc($cursorFinal),
                    'updated_at' => $registrada,
                ]);

            if ($this->mysql->transStatus() === false) {
                throw new RuntimeException('MySQL no pudo guardar el lote de buckets y el cursor.');
            }

            if (! $this->mysql->transCommit()) {
                throw new RuntimeException('MySQL no pudo confirmar el lote de buckets y el cursor.');
            }

            return [
                'insertados' => $insertados,
                'yaExistentes' => count($buckets) - $insertados,
            ];
        } catch (Throwable $exception) {
            $this->mysql->transRollback();
            $this->mysql->resetTransStatus();

            throw $exception;
        }
    }

    /** @return array<string,mixed> */
    private function fila(
        int $empresaId,
        int $integracionId,
        int $equipoId,
        string $unidadExterna,
        string $proveedor,
        BucketTelemetria $bucket,
        string $registrada,
    ): array {
        $primero = $bucket->primero();
        $ultimo = $bucket->ultimo();
        $posicionInicio = $primero->posicion();
        $posicionFin = $ultimo->posicion();

        return [
            'empresa_id' => $empresaId,
            'integracion_id' => $integracionId,
            'equipo_id' => $equipoId,
            'unidad_externa' => $unidadExterna,
            'proveedor' => $proveedor,
            'bucket_inicio_utc' => $this->fechaUtc($bucket->inicio()),
            'bucket_fin_utc' => $this->fechaUtc($bucket->fin()),
            'primera_observada_en' => $this->fechaUtc($primero->observadaEn()),
            'ultima_observada_en' => $this->fechaUtc($ultimo->observadaEn()),
            'combustible_inicio_litros' => $primero->combustibleLitros(),
            'combustible_fin_litros' => $ultimo->combustibleLitros(),
            'kilometraje_inicio' => $primero->kilometraje(),
            'kilometraje_fin' => $ultimo->kilometraje(),
            'horas_decimales_inicio' => $primero->horasDecimales(),
            'horas_decimales_fin' => $ultimo->horasDecimales(),
            'motor_inicio' => $this->booleano($primero->motorEncendido()),
            'motor_fin' => $this->booleano($ultimo->motorEncendido()),
            'ralenti_inicio' => $this->booleano($primero->ralentiActivo()),
            'ralenti_fin' => $this->booleano($ultimo->ralentiActivo()),
            'segundos_ralenti' => $bucket->segundosRalenti(),
            'latitud_inicio' => $posicionInicio?->latitude(),
            'longitud_inicio' => $posicionInicio?->longitude(),
            'velocidad_inicio_kmh' => $posicionInicio?->speedKmh(),
            'rumbo_inicio' => $posicionInicio?->course(),
            'altitud_inicio_m' => $posicionInicio?->altitudeMeters(),
            'satelites_inicio' => $posicionInicio?->satellites(),
            'latitud_fin' => $posicionFin?->latitude(),
            'longitud_fin' => $posicionFin?->longitude(),
            'velocidad_fin_kmh' => $posicionFin?->speedKmh(),
            'rumbo_fin' => $posicionFin?->course(),
            'altitud_fin_m' => $posicionFin?->altitudeMeters(),
            'satelites_fin' => $posicionFin?->satellites(),
            'mensajes_fuente_json' => json_encode(
                $bucket->mensajesFuente(),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
            ),
            'created_at' => $registrada,
        ];
    }

    private function booleano(?bool $valor): ?int
    {
        return $valor === null ? null : ($valor ? 1 : 0);
    }

    private function fechaUtc(DateTimeImmutable $fecha): string
    {
        return $fecha->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
