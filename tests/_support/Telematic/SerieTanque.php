<?php

declare(strict_types=1);

namespace Tests\Support\Telematic;

use App\Domain\Telematic\InstantaneaEquipo;
use App\Domain\Telematic\MuestraEquipo;
use DateTimeImmutable;
use DateTimeZone;

/** Arma series de tanque para los tests: una muestra cada N minutos. */
final class SerieTanque
{
    /**
     * @param list<float|null>      $litros Nivel de cada muestra; null = sensor sin dato.
     * @param list<int|null>|null   $km     Odómetro de cada muestra; por defecto, parado.
     *
     * @return list<MuestraEquipo>
     */
    public static function cada(int $minutos, string $desde, array $litros, ?array $km = null): array
    {
        $zona = new DateTimeZone('America/Argentina/Buenos_Aires');
        $inicio = new DateTimeImmutable($desde, $zona);
        $muestras = [];

        foreach (array_values($litros) as $i => $nivel) {
            $en = $inicio->modify('+' . ($i * $minutos) . ' minutes');
            $odometro = $km === null ? 100000 : $km[$i];
            $muestras[] = new MuestraEquipo(
                4,
                1,
                42,
                'WIALON-42',
                'wialon',
                new InstantaneaEquipo($en, null, $odometro, null, false, false, 24.8, $nivel),
                $en,
            );
        }

        return $muestras;
    }

    /** Une series ya ordenadas. @param list<MuestraEquipo> ...$series @return list<MuestraEquipo> */
    public static function unir(array ...$series): array
    {
        return array_merge(...$series);
    }
}
