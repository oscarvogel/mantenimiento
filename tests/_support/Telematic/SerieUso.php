<?php

declare(strict_types=1);

namespace Tests\Support\Telematic;

use App\Domain\Telematic\InstantaneaEquipo;
use App\Domain\Telematic\MuestraEquipo;
use App\Domain\Telematic\Posicion;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Arma series de uso para los tests. Cada paso es
 * `[minutosDesdeElInicio, velocidad|null, motor|null, ralenti|null, km|null, decimasHorometro|null]`.
 */
final class SerieUso
{
    /**
     * @param list<array{0:int,1:?float,2:?bool,3:?bool,4:?int,5:?int}> $pasos
     *
     * @return list<MuestraEquipo>
     */
    public static function pasos(string $desde, array $pasos, int $equipoId = 42, int $integracionId = 1): array
    {
        $inicio = new DateTimeImmutable($desde, new DateTimeZone('America/Argentina/Buenos_Aires'));
        $muestras = [];

        foreach ($pasos as [$minutos, $velocidad, $motor, $ralenti, $km, $horas]) {
            $en = $inicio->modify('+' . $minutos . ' minutes');
            $muestras[] = new MuestraEquipo(
                4,
                $integracionId,
                $equipoId,
                'U-' . $equipoId,
                'wialon',
                new InstantaneaEquipo(
                    $en,
                    new Posicion(-33.0, -68.8, $velocidad, null, null, null, $en),
                    $km,
                    $horas,
                    $motor,
                    $ralenti,
                    24.8,
                    null,
                ),
                $en,
            );
        }

        return $muestras;
    }
}
