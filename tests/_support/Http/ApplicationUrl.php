<?php

declare(strict_types=1);

namespace Tests\Support\Http;

/**
 * Resuelve una ruta de la aplicacion contra el baseURL del entorno.
 *
 * La app se publica en la raiz (local y CI) o bajo un subdirectorio
 * (`/mantenimiento/` en Ferozo), y el baseURL cambia con el entorno. Un test
 * que escribe un path absoluto queda atado a una maquina: el mismo assert
 * pasa en CI y falla en local, o al reves.
 *
 * Lo que importa es que la URL apunte a la ruta correcta, no si el prefijo
 * aparece una o dos veces. Esta clase deja la comparacion en terminos de la
 * ruta, y el prefijo lo aporta el entorno.
 */
final class ApplicationUrl
{
    /**
     * Path de una ruta de la aplicacion, tal como queda con el baseURL vigente.
     */
    public static function path(string $ruta): string
    {
        $base = parse_url(base_url(''), PHP_URL_PATH);
        $base = is_string($base) ? $base : '/';

        return rtrim($base, '/') . '/' . ltrim($ruta, '/');
    }
}
