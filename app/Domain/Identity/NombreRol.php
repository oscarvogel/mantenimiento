<?php

declare(strict_types=1);

namespace App\Domain\Identity;

/**
 * Nombres de rol del sistema.
 *
 * El rol Administrador tiene una regla propia: alcanza todas las sucursales
 * de la empresa. Esa regla se resuelve al LEER el actor, nunca borrando las
 * asignaciones del usuario, porque un ida y vuelta de roles no puede dejar a
 * nadie sin alcance.
 */
final class NombreRol
{
    public const ADMINISTRADOR = 'Administrador';
    public const MANTENIMIENTO = 'Mantenimiento';

    /** @param list<string> $nombres */
    public static function esAdministrador(array $nombres): bool
    {
        return in_array(self::ADMINISTRADOR, $nombres, true);
    }
}