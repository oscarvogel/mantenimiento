<?php

declare(strict_types=1);

namespace App\Infrastructure\Assets;

use App\Application\Assets\Port\EquipmentReadModel;
use CodeIgniter\Database\BaseConnection;

final class CodeIgniterEquipmentReadModel implements EquipmentReadModel
{
    public function __construct(private readonly BaseConnection $database)
    {
    }

    public function findDetails(
        int $companyId,
        int $equipmentId,
        ?array $branchIds,
        int $transferPage,
        int $transfersPerPage,
        int $relationPage = 1,
        int $relationsPerPage = 20,
    ): ?array {
        if ($branchIds === []) {
            return null;
        }

        $builder = $this->database->table('equipos e')
            ->select('e.id, e.empresa_id, e.sucursal_id, e.tipo_equipo_id, e.codigo, e.patente, e.marca_id, ma.nombre marca_nombre, e.modelo_id, mo.nombre modelo_nombre, e.anio, e.chasis, e.motor, e.km_actual, e.horas_actuales, e.estado, e.fecha_alta, e.fecha_baja, e.observaciones, e.created_at, e.updated_at, s.codigo sucursal_codigo, s.nombre sucursal_nombre, te.nombre tipo_nombre, te.controla_km, te.controla_horas')
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

        $historyTotal = $this->database->table('equipo_sucursal_historial')
            ->where('empresa_id', $companyId)
            ->where('equipo_id', $equipmentId)
            ->countAllResults();
        $history = $this->database->table('equipo_sucursal_historial h')
            ->select('h.id, h.sucursal_origen_id, h.sucursal_destino_id, h.fecha_movimiento, h.usuario_id, h.motivo, so.codigo sucursal_origen_codigo, so.nombre sucursal_origen_nombre, sd.codigo sucursal_destino_codigo, sd.nombre sucursal_destino_nombre, u.nombre usuario_nombre')
            ->join('sucursales so', 'so.id = h.sucursal_origen_id AND so.empresa_id = h.empresa_id', 'inner')
            ->join('sucursales sd', 'sd.id = h.sucursal_destino_id AND sd.empresa_id = h.empresa_id', 'inner')
            ->join('usuarios u', 'u.id = h.usuario_id', 'inner')
            ->where('h.empresa_id', $companyId)
            ->where('h.equipo_id', $equipmentId)
            ->orderBy('h.fecha_movimiento', 'DESC')
            ->orderBy('h.id', 'DESC')
            ->limit($transfersPerPage, ($transferPage - 1) * $transfersPerPage)
            ->get()->getResultArray();

        $relationsBuilder = $this->database->table('equipo_relaciones r')
            ->select('r.id, r.equipo_principal_id, ep.codigo equipo_principal_codigo, r.equipo_relacionado_id, er.codigo equipo_relacionado_codigo, r.tipo_relacion, r.desde, r.hasta, r.usuario_id, u.nombre usuario_nombre, r.finalizado_por, uf.nombre finalizado_por_nombre, r.observaciones, r.observaciones_fin')
            ->join('equipos ep', 'ep.id = r.equipo_principal_id AND ep.empresa_id = r.empresa_id', 'inner')
            ->join('equipos er', 'er.id = r.equipo_relacionado_id AND er.empresa_id = r.empresa_id', 'inner')
            ->join('usuarios u', 'u.id = r.usuario_id', 'inner')
            ->join('usuarios uf', 'uf.id = r.finalizado_por', 'left')
            ->where('r.empresa_id', $companyId)
            ->groupStart()->where('r.equipo_principal_id', $equipmentId)->orWhere('r.equipo_relacionado_id', $equipmentId)->groupEnd();
        if ($branchIds !== null) {
            $relationsBuilder->whereIn('ep.sucursal_id', $branchIds)->whereIn('er.sucursal_id', $branchIds);
        }
        $relationsTotal = (clone $relationsBuilder)->countAllResults();
        $relations = $relationsBuilder->orderBy('r.desde', 'DESC')->orderBy('r.id', 'DESC')
            ->limit($relationsPerPage, ($relationPage - 1) * $relationsPerPage)->get()->getResultArray();

        return [
            'equipment' => $equipment,
            'transferHistory' => $history,
            'transferHistoryTotal' => $historyTotal,
            'transferHistoryPage' => $transferPage,
            'transferHistoryPerPage' => $transfersPerPage,
            'transferHistoryTotalPages' => max(1, (int) ceil($historyTotal / $transfersPerPage)),
            'relations' => $relations,
            'relationsTotal' => $relationsTotal,
            'relationsPage' => $relationPage,
            'relationsPerPage' => $relationsPerPage,
            'relationsTotalPages' => max(1, (int) ceil($relationsTotal / $relationsPerPage)),
        ];
    }

    public function listAvailableBranches(int $companyId, ?array $branchIds): array
    {
        if ($branchIds === []) {
            return [];
        }

        $builder = $this->database->table('sucursales')
            ->select('id, codigo, nombre')
            ->where('empresa_id', $companyId)
            ->where('estado', 1)
            ->where('deleted_at', null)
            ->orderBy('nombre');
        if ($branchIds !== null) {
            $builder->whereIn('id', $branchIds);
        }

        return $builder->get()->getResultArray();
    }
}
