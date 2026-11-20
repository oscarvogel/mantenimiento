<?php

declare(strict_types=1);

namespace App\Infrastructure\Trips;

use App\Application\Trips\OperationalTripFilters;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;

/**
 * Filtros por camión, cliente y chofer sobre una consulta de viajes con alias
 * `v`. Un viaje entra si en algún momento tuvo ese camión o chofer asignado, o
 * si alguno de sus tramos es de ese cliente.
 */
final class OperationalTripScopeConditions
{
    public static function apply(BaseBuilder $builder, BaseConnection $db, int $companyId, OperationalTripFilters $filters): void
    {
        if ($filters->equipmentId !== null) {
            $builder->whereIn('v.id', $db->table('viaje_operativo_asignaciones')
                ->select('viaje_id')
                ->where('empresa_id', $companyId)
                ->where('rol', 'TRACTOR')
                ->where('equipo_id', $filters->equipmentId));
        }
        if ($filters->driverId !== null) {
            $builder->whereIn('v.id', $db->table('viaje_operativo_asignaciones')
                ->select('viaje_id')
                ->where('empresa_id', $companyId)
                ->where('rol', 'CHOFER')
                ->where('empleado_id', $filters->driverId));
        }
        if ($filters->customerId !== null) {
            $builder->whereIn('v.id', $db->table('viaje_operativo_tramos')
                ->select('viaje_id')
                ->where('empresa_id', $companyId)
                ->where('anulado_at', null)
                ->where('cliente_id', $filters->customerId));
        }
    }
}
