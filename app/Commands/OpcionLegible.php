<?php

declare(strict_types=1);

namespace App\Commands;

/**
 * Lee opciones de spark aceptando las dos sintaxis.
 *
 * CodeIgniter **no** soporta `--clave=valor`. En `CLI::parseCommandLine()` el
 * nombre se saca con `ltrim($arg, '-')` y el valor se toma del **siguiente**
 * argumento. O sea que `--empresa=4` llega al comando como la clave
 * `"empresa=4"` con valor `null`, y buscar `$params['empresa']` no encuentra
 * nada.
 *
 * Como todo el mundo escribe `--empresa=4` y el `usage` de los comandos lo
 * documenta así, normalizar acá evita que un comando que se ve bien escrito
 * falle en silencio diciendo "falta el parámetro".
 */
trait OpcionLegible
{
    protected function opcion(array $params, string $clave, ?string $defecto = null): ?string
    {
        $valor = $params[$clave] ?? null;

        if (is_string($valor) && $valor !== '') {
            return $valor;
        }

        foreach (array_keys($params) as $nombre) {
            if (! is_string($nombre) || ! str_starts_with($nombre, $clave . '=')) {
                continue;
            }

            $pegado = substr($nombre, strlen($clave) + 1);

            if ($pegado !== '') {
                return $pegado;
            }

            // `--clave=` sin valor y sin argumento siguiente: es un flag.
            return $valor === true ? '1' : ($pegado !== '' ? $pegado : $defecto);
        }

        return $defecto;
    }

    protected function opcionEntera(array $params, string $clave, int $defecto = 0): int
    {
        $valor = $this->opcion($params, $clave);

        return $valor === null ? $defecto : (int) $valor;
    }
}