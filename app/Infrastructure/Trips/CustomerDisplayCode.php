<?php

declare(strict_types=1);

namespace App\Infrastructure\Trips;

/**
 * Código del cliente que se muestra al usuario.
 *
 * Los clientes importados traen el código del sistema de origen (por ejemplo,
 * 342 del ERP de transporte) y sirve para reconocerlos. Los creados a mano en
 * Maestros reciben una clave técnica aleatoria solo para la unicidad
 * (origen, codigo_origen): no significa nada para una persona y no se muestra.
 */
final class CustomerDisplayCode
{
    public const MANUAL_ORIGIN = 'MANUAL_VIAJES';

    public static function for(?string $origin, ?string $code): ?string
    {
        if ($code === null || trim($code) === '' || $origin === self::MANUAL_ORIGIN) {
            return null;
        }

        return $code;
    }
}
