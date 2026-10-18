<?php

declare(strict_types=1);

namespace App\Domain\Trips;

/**
 * Decide si la diferencia entre las lecturas de odometro de un viaje es
 * utilizable como kilometraje.
 *
 * El legacy de transporte arranca el odometro distinto por dominio y tiene
 * saltos de carga/reset (hasta 1,5 millones de km en un viaje). Un delta mayor
 * al tope se descarta como outlier en vez de inflar los totales. Un regreso
 * anterior a la salida no se calcula: se conserva el viaje y se informa aparte.
 */
final class TripDistance
{
    public const OK = 'OK';
    public const MISSING = 'MISSING';
    public const REVERSED = 'REVERSED';
    public const OUTLIER = 'OUTLIER';

    public function __construct(private readonly int $maximumKm = 15_000)
    {
    }

    public function status(?int $start, ?int $end): string
    {
        if ($start === null || $end === null) {
            return self::MISSING;
        }
        if ($end <= $start) {
            return self::REVERSED;
        }
        if ($end - $start > $this->maximumKm) {
            return self::OUTLIER;
        }

        return self::OK;
    }

    public function reliable(?int $start, ?int $end): ?int
    {
        return $this->status($start, $end) === self::OK ? $end - $start : null;
    }
}
