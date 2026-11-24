<?php

declare(strict_types=1);

namespace App\Infrastructure\Trips;

use CodeIgniter\Database\BaseConnection;
use Throwable;

/**
 * Lectura del porcentaje de liquidación por defecto de la empresa.
 *
 * Es el respaldo para los choferes que no tienen fila en el maestro de
 * porcentajes de liquidación (por ejemplo, los que se dieron de alta después
 * de la última carga del maestro).
 *
 * La tabla puede no existir todavía mientras la migración no se aplicó: en ese
 * caso no hay porcentaje por defecto y el cálculo sigue el camino de siempre,
 * sin romper la pantalla.
 */
final class DriverLiquidationDefault
{
    public static function read(BaseConnection $database, int $companyId): ?float
    {
        if ($companyId <= 0) {
            return null;
        }

        try {
            $row = $database->table('viajes_porcentaje_liquidacion_defecto')
                ->select('porcentaje')
                ->where('empresa_id', $companyId)
                ->get()->getRowArray();
        } catch (Throwable) {
            return null;
        }

        if ($row === null || $row['porcentaje'] === null) {
            return null;
        }

        return (float) $row['porcentaje'];
    }
}
