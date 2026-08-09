<?php

declare(strict_types=1);

namespace App\Infrastructure\Assets;

use App\Application\Assets\Port\EquipmentListReadModel;
use App\Application\Assets\Port\EquipmentQrReadModel;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;

final class CodeIgniterEquipmentSearch implements EquipmentListReadModel, EquipmentQrReadModel
{
    public function __construct(private readonly BaseConnection $database) {}

    public function search(
        int $companyId, ?array $branchIds, ?string $query, ?int $typeId, ?int $brandId,
        ?int $branchId, ?string $status, int $page, int $perPage,
    ): array {
        if ($branchIds === []) {
            return ['items' => [], 'total' => 0];
        }
        $countBuilder = $this->base($companyId, $branchIds);
        $this->filters($countBuilder, $query, $typeId, $brandId, $branchId, $status);
        $total = $countBuilder->countAllResults();
        $itemsBuilder = $this->base($companyId, $branchIds);
        $this->filters($itemsBuilder, $query, $typeId, $brandId, $branchId, $status);
        $items = $itemsBuilder
            ->select('e.id, e.codigo, e.patente, e.anio, e.chasis, e.motor, e.km_actual, e.horas_actuales, e.estado, e.fecha_alta, e.fecha_baja, e.sucursal_id, s.codigo sucursal_codigo, s.nombre sucursal_nombre, e.tipo_equipo_id, te.nombre tipo_nombre, e.marca_id, ma.nombre marca_nombre, e.modelo_id, mo.nombre modelo_nombre')
            ->orderBy('e.codigo')->limit($perPage, ($page - 1) * $perPage)->get()->getResultArray();

        return ['items' => $items, 'total' => $total];
    }

    public function findScoped(int $companyId, int $equipmentId, ?array $branchIds): ?array
    {
        if ($branchIds === []) {
            return null;
        }
        return $this->base($companyId, $branchIds)->select('e.id, e.codigo')->where('e.id', $equipmentId)->get()->getRowArray();
    }

    /** @param list<int>|null $branchIds */
    private function base(int $companyId, ?array $branchIds): BaseBuilder
    {
        $builder = $this->database->table('equipos e')
            ->join('sucursales s', 's.id = e.sucursal_id AND s.empresa_id = e.empresa_id', 'inner')
            ->join('tipos_equipo te', 'te.id = e.tipo_equipo_id', 'inner')
            ->join('marcas ma', 'ma.id = e.marca_id AND ma.empresa_id = e.empresa_id', 'left')
            ->join('modelos mo', 'mo.id = e.modelo_id AND mo.empresa_id = e.empresa_id', 'left')
            ->where('e.empresa_id', $companyId)->where('e.deleted_at', null);
        if ($branchIds !== null) {
            $builder->whereIn('e.sucursal_id', $branchIds);
        }
        return $builder;
    }

    private function filters(BaseBuilder $builder, ?string $query, ?int $typeId, ?int $brandId, ?int $branchId, ?string $status): void
    {
        if ($query !== null) {
            $builder->groupStart()->like('e.codigo', $query)->orLike('e.patente', $query)->orLike('e.chasis', $query)->groupEnd();
        }
        if ($typeId !== null) { $builder->where('e.tipo_equipo_id', $typeId); }
        if ($brandId !== null) { $builder->where('e.marca_id', $brandId); }
        if ($branchId !== null) { $builder->where('e.sucursal_id', $branchId); }
        if ($status !== null) { $builder->where('e.estado', $status); }
    }
}
