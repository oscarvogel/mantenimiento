<?php

declare(strict_types=1);

namespace App\Infrastructure\DriverPortal;

use App\Application\DriverPortal\Port\DriverPortalReadModel;
use CodeIgniter\Database\BaseConnection;

final class CodeIgniterDriverPortalReadModel implements DriverPortalReadModel
{
    public function __construct(private readonly BaseConnection $database)
    {
    }

    public function findScoped(int $companyId, int $equipmentId, ?array $branchIds): ?array
    {
        if ($branchIds === []) {
            return null;
        }

        $builder = $this->database->table('equipos e')
            ->select('e.id, e.codigo, e.patente, e.km_actual, e.horas_actuales, e.estado, e.sucursal_id')
            ->select('te.nombre tipo_nombre, te.controla_km, te.controla_horas')
            ->select('s.nombre sucursal_nombre, ma.nombre marca_nombre, mo.nombre modelo_nombre')
            ->join('sucursales s', 's.id = e.sucursal_id AND s.empresa_id = e.empresa_id', 'inner')
            ->join('tipos_equipo te', 'te.id = e.tipo_equipo_id', 'inner')
            ->join('marcas ma', 'ma.id = e.marca_id AND ma.empresa_id = e.empresa_id', 'left')
            ->join('modelos mo', 'mo.id = e.modelo_id AND mo.empresa_id = e.empresa_id', 'left')
            ->where('e.empresa_id', $companyId)
            ->where('e.id', $equipmentId)
            ->where('e.deleted_at', null);
        if ($branchIds !== null) {
            $builder->whereIn('e.sucursal_id', $branchIds);
        }

        $equipment = $builder->get()->getRowArray();
        if ($equipment === null) {
            return null;
        }

        $lastReading = $this->database->table('lecturas_equipo l')
            ->select('l.fecha_lectura, l.kilometraje, l.horometro, u.nombre usuario_nombre')
            ->join('usuarios u', 'u.id = l.usuario_id', 'inner')
            ->where('l.empresa_id', $companyId)
            ->where('l.equipo_id', $equipmentId)
            ->where('l.anulada', 0)
            ->orderBy('l.fecha_lectura', 'DESC')
            ->orderBy('l.id', 'DESC')
            ->limit(1)
            ->get()
            ->getRowArray();

        $equipment['ultima_lectura_fecha'] = $lastReading['fecha_lectura'] ?? null;
        $equipment['ultima_lectura_km'] = $lastReading['kilometraje'] ?? null;
        $equipment['ultima_lectura_horas'] = $lastReading['horometro'] ?? null;
        $equipment['ultima_lectura_usuario'] = $lastReading['usuario_nombre'] ?? null;

        return $equipment;
    }
}
