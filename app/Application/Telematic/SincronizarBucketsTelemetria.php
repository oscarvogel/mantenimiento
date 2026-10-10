<?php

declare(strict_types=1);

namespace App\Application\Telematic;

use App\Application\Telematic\Port\LectorHistoricoTelemetria;
use App\Application\Telematic\Port\TelemetryBucketStore;
use App\Domain\Telematic\AgregadorBucketsTelemetria;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** Sincroniza la serie histórica de cada unidad vinculada, con cursor propio. */
final readonly class SincronizarBucketsTelemetria
{
    private const DURACION_BUCKET_SEGUNDOS = 300;
    private const SOLAPE_SEGUNDOS = 1800;
    private const RETENCION_DIAS = 90;

    public function __construct(
        private LectorHistoricoTelemetria $lector,
        private TelemetryBucketStore $buckets,
        private AgregadorBucketsTelemetria $agregador,
    ) {
    }

    /**
     * @param array<string,int> $unidadesVinculadas Identificador externo => equipo dueño
     * @return array{insertados:int, yaExistentes:int} Escrituras nuevas y
     * repetidas ignoradas por la clave idempotente.
     */
    public function sincronizar(
        int $empresaId,
        int $integrationId,
        string $provider,
        array $unidadesVinculadas,
        DateTimeImmutable $ahora,
        ?LectorHistoricoTelemetria $lector = null,
    ): array {
        if ($empresaId <= 0 || $integrationId <= 0 || trim($provider) === '') {
            throw new InvalidArgumentException('La sincronización necesita empresa, integración y proveedor válidos.');
        }

        $utc = new DateTimeZone('UTC');
        $ahoraUtc = $ahora->setTimezone($utc);
        $hasta = $this->limiteAlineado($ahoraUtc);
        $resultado = ['insertados' => 0, 'yaExistentes' => 0];

        foreach ($unidadesVinculadas as $unidadExterna => $equipoId) {
            $unidadExterna = (string) $unidadExterna;
            if (trim($unidadExterna) === '' || ! is_int($equipoId) || $equipoId <= 0) {
                throw new InvalidArgumentException('Cada unidad histórica debe pertenecer a un equipo válido.');
            }

            $cursor = $this->buckets->cursor($empresaId, $integrationId, $unidadExterna);
            if ($cursor === null) {
                $desde = $this->limiteAlineado($ahoraUtc->modify('-' . self::RETENCION_DIAS . ' days'));
            } else {
                $desde = $cursor->setTimezone($utc)->modify('-' . self::SOLAPE_SEGUNDOS . ' seconds');
            }

            // Un reloj atrasado o una unidad sin ventana cerrada no puede
            // hacer retroceder el cursor ni provocar una consulta invertida.
            if ($hasta < $desde || ($cursor !== null && $hasta < $cursor->setTimezone($utc))) {
                continue;
            }

            $mensajes = ($lector ?? $this->lector)->mensajesEntre($integrationId, $unidadExterna, $desde, $hasta);
            $cerrados = $this->agregador->agrupar($mensajes, $hasta, self::DURACION_BUCKET_SEGUNDOS);

            // Un array vacío es una lectura exitosa y comprobada: el cursor
            // avanza igualmente para no volver a descargar la misma ventana.
            // Una excepción del lector deja este punto sin ejecución.
            $guardado = $this->buckets->guardarCerradosYAvanzarCursor(
                $empresaId,
                $integrationId,
                $equipoId,
                $unidadExterna,
                $provider,
                $cerrados,
                $hasta,
                $ahoraUtc,
            );
            $resultado['insertados'] += $guardado['insertados'];
            $resultado['yaExistentes'] += $guardado['yaExistentes'];
        }

        return $resultado;
    }

    private function limiteAlineado(DateTimeImmutable $fecha): DateTimeImmutable
    {
        $segundos = intdiv($fecha->getTimestamp(), self::DURACION_BUCKET_SEGUNDOS) * self::DURACION_BUCKET_SEGUNDOS;

        return (new DateTimeImmutable('@' . $segundos))->setTimezone(new DateTimeZone('UTC'));
    }
}
