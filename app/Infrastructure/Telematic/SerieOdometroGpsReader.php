<?php

declare(strict_types=1);

namespace App\Infrastructure\Telematic;

use App\Application\PreventiveMaintenance\Port\OdometroGpsReader;
use App\Application\Telematic\Port\SerieMuestrasReader;
use App\Domain\PreventiveMaintenance\Gps\OdometroGps;
use App\Domain\Telematic\MuestraEquipo;
use DateTimeImmutable;

/**
 * Adaptador de Telemetría para el contrato `OdometroGpsReader` de Preventivo.
 *
 * Lee la serie de muestras (que exige la empresa y junta muestras y buckets) y
 * entrega solo el odómetro de cada una. No interpreta: anclar y validar la
 * diferencia es regla de Preventivo.
 */
final readonly class SerieOdometroGpsReader implements OdometroGpsReader
{
    public function __construct(private SerieMuestrasReader $serie)
    {
    }

    public function odometros(int $empresaId, int $equipoId, DateTimeImmutable $desde, DateTimeImmutable $hasta): array
    {
        $odometros = [];
        foreach ($this->serie->serie($empresaId, $equipoId, $desde, $hasta) as $muestra) {
            /** @var MuestraEquipo $muestra */
            $km = $muestra->kilometraje();
            if ($km !== null && $km >= 0) {
                $odometros[] = new OdometroGps($muestra->observadaEn(), $km);
            }
        }

        return $odometros;
    }
}
