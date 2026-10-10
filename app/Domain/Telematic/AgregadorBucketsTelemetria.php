<?php

declare(strict_types=1);

namespace App\Domain\Telematic;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** Agrupa mensajes en ventanas temporales sin interpolar métricas ausentes. */
final class AgregadorBucketsTelemetria
{
    private const HUECO_MAXIMO_SEGUNDOS = 1800;

    /**
     * @param list<MensajeHistorico> $mensajes
     *
     * @return list<BucketTelemetria>
     */
    public function agrupar(
        array $mensajes,
        DateTimeImmutable $hastaCerrado,
        int $duracionSegundos = 300,
    ): array {
        if ($duracionSegundos <= 0) {
            throw new InvalidArgumentException('La duración del bucket debe ser positiva.');
        }

        $limite = $hastaCerrado->getTimestamp();
        $ordenados = [];

        foreach ($mensajes as $indice => $mensaje) {
            if (! $mensaje instanceof MensajeHistorico) {
                throw new InvalidArgumentException('La serie solo puede contener mensajes históricos.');
            }

            if ($mensaje->observadaEn()->getTimestamp() <= $limite) {
                $ordenados[] = ['mensaje' => $mensaje, 'indice' => $indice];
            }
        }

        usort($ordenados, static function (array $a, array $b): int {
            $porFecha = $a['mensaje']->observadaEn()->getTimestamp() <=> $b['mensaje']->observadaEn()->getTimestamp();

            return $porFecha !== 0 ? $porFecha : ($a['indice'] <=> $b['indice']);
        });

        // Wialon puede repetir un mensaje al solapar páginas. Conservamos la
        // primera aparición del timestamp: el orden estable la hace reproducible.
        $unicos = [];
        $timestamps = [];
        foreach ($ordenados as $item) {
            $timestamp = $item['mensaje']->observadaEn()->getTimestamp();
            if (isset($timestamps[$timestamp])) {
                continue;
            }
            $timestamps[$timestamp] = true;
            $unicos[] = $item['mensaje'];
        }

        if (count($unicos) < 2) {
            return [];
        }

        $grupos = [];
        foreach ($unicos as $mensaje) {
            $inicioTimestamp = $this->inicioDeBucket($mensaje->observadaEn()->getTimestamp(), $duracionSegundos);
            $grupos[$inicioTimestamp][] = $mensaje;
        }

        [$segundosRalenti, $bucketConCobertura, $bucketConHueco] = $this->duracionRalentiPorBucket($unicos, $duracionSegundos);
        $zonaUtc = new DateTimeZone('UTC');
        $buckets = [];

        foreach ($grupos as $inicioTimestamp => $mensajesDelBucket) {
            $finTimestamp = $inicioTimestamp + $duracionSegundos;
            if ($finTimestamp > $limite || ! $this->tieneEvidenciaDeCierre($unicos, $finTimestamp)) {
                continue;
            }

            $buckets[] = new BucketTelemetria(
                (new DateTimeImmutable('@' . $inicioTimestamp))->setTimezone($zonaUtc),
                (new DateTimeImmutable('@' . $finTimestamp))->setTimezone($zonaUtc),
                $mensajesDelBucket[0],
                $mensajesDelBucket[array_key_last($mensajesDelBucket)],
                isset($bucketConHueco[$inicioTimestamp])
                    ? null
                    : (isset($bucketConCobertura[$inicioTimestamp])
                        ? ($segundosRalenti[$inicioTimestamp] ?? 0)
                        : null),
            );
        }

        return $buckets;
    }

    private function inicioDeBucket(int $timestamp, int $duracionSegundos): int
    {
        return intdiv($timestamp, $duracionSegundos) * $duracionSegundos;
    }

    /** @param list<MensajeHistorico> $mensajes */
    private function tieneEvidenciaDeCierre(array $mensajes, int $finTimestamp): bool
    {
        foreach ($mensajes as $mensaje) {
            if ($mensaje->observadaEn()->getTimestamp() >= $finTimestamp) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<MensajeHistorico> $mensajes
     * @return array{array<int,int>, array<int,true>, array<int,true>}
     */
    private function duracionRalentiPorBucket(array $mensajes, int $duracionSegundos): array
    {
        $segundos = [];
        $cobertura = [];
        $huecos = [];

        for ($indice = 1, $cantidad = count($mensajes); $indice < $cantidad; $indice++) {
            $anterior = $mensajes[$indice - 1];
            $actual = $mensajes[$indice];
            $desde = $anterior->observadaEn()->getTimestamp();
            $hasta = $actual->observadaEn()->getTimestamp();
            if ($hasta <= $desde) {
                continue;
            }

            $primerBucket = $this->inicioDeBucket($desde, $duracionSegundos);
            $ultimoBucket = $this->inicioDeBucket($hasta - 1, $duracionSegundos);

            if ($hasta - $desde > self::HUECO_MAXIMO_SEGUNDOS
                || $anterior->ralentiActivo() === null
                || $actual->ralentiActivo() === null
            ) {
                for ($bucket = $primerBucket; $bucket <= $ultimoBucket; $bucket += $duracionSegundos) {
                    $huecos[$bucket] = true;
                }
                continue;
            }

            for ($bucket = $primerBucket; $bucket <= $ultimoBucket; $bucket += $duracionSegundos) {
                $inicioIntervalo = max($desde, $bucket);
                $finIntervalo = min($hasta, $bucket + $duracionSegundos);
                if ($finIntervalo > $inicioIntervalo) {
                    $cobertura[$bucket] = true;
                }
            }

            if ($anterior->ralentiActivo() !== true) {
                // Una transición observada a ralentí comienza en el mensaje
                // actual, no antes de que el proveedor la haya reportado.
                continue;
            }

            for ($bucket = $primerBucket; $bucket <= $ultimoBucket; $bucket += $duracionSegundos) {
                $inicioIntervalo = max($desde, $bucket);
                $finIntervalo = min($hasta, $bucket + $duracionSegundos);
                $segundos[$bucket] = ($segundos[$bucket] ?? 0) + max(0, $finIntervalo - $inicioIntervalo);
            }
        }

        return [$segundos, $cobertura, $huecos];
    }
}
