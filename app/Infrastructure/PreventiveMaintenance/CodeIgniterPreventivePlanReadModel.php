<?php

declare(strict_types=1);

namespace App\Infrastructure\PreventiveMaintenance;

use App\Application\PreventiveMaintenance\Port\PreventivePlanReadModel;
use App\Application\PreventiveMaintenance\PreventivePlanListItem;
use App\Domain\PreventiveMaintenance\PlanMantenimiento;
use App\Domain\PreventiveMaintenance\UsoActual;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;

final readonly class CodeIgniterPreventivePlanReadModel implements PreventivePlanReadModel
{
    public function __construct(private BaseConnection $database)
    {
    }

    public function listActive(int $companyId, ?array $branchIds): array
    {
        $builder = $this->database->table('planes_mantenimiento p')
            ->select('p.*, e.sucursal_id, e.codigo equipo_codigo, e.patente equipo_patente, e.km_actual, e.horas_actuales, s.codigo sucursal_codigo, s.nombre sucursal_nombre, te.nombre tipo_equipo_nombre, ts.nombre servicio_nombre')
            ->join('equipos e', 'e.id = p.equipo_id AND e.empresa_id = p.empresa_id', 'inner')
            ->join('sucursales s', 's.id = e.sucursal_id AND s.empresa_id = e.empresa_id', 'inner')
            ->join('tipos_equipo te', 'te.id = e.tipo_equipo_id', 'inner')
            ->join('tipos_servicio ts', 'ts.id = p.tipo_servicio_id', 'inner')
            ->where('p.empresa_id', $companyId)
            ->where('p.activo', 1)
            ->where('p.deleted_at', null)
            ->where('e.deleted_at', null)
            ->where('s.deleted_at', null)
            ->orderBy('e.codigo', 'ASC')
            ->orderBy('ts.nombre', 'ASC');
        $this->scopeBranches($builder, 'e.sucursal_id', $branchIds);

        return array_map(fn (array $row): PreventivePlanListItem => new PreventivePlanListItem(
            $this->hydratePlan($row),
            new UsoActual(
                $row['km_actual'] === null ? null : (int) $row['km_actual'],
                DecimalHours::toTenths($row['horas_actuales']),
            ),
            (string) $row['equipo_codigo'],
            $row['equipo_patente'] === null ? null : (string) $row['equipo_patente'],
            (int) $row['sucursal_id'],
            (string) $row['sucursal_codigo'],
            (string) $row['sucursal_nombre'],
            (string) $row['tipo_equipo_nombre'],
            (string) $row['servicio_nombre'],
        ), $builder->get()->getResultArray());
    }

    public function listActiveEquipment(int $companyId, ?array $branchIds): array
    {
        $builder = $this->database->table('equipos e')
            ->select('e.id, e.codigo, e.patente, e.sucursal_id, e.tipo_equipo_id, e.km_actual, e.horas_actuales, s.codigo sucursal_codigo, s.nombre sucursal_nombre, te.nombre tipo_nombre, te.controla_km, te.controla_horas, ma.nombre marca_nombre, mo.nombre modelo_nombre')
            ->join('sucursales s', 's.id = e.sucursal_id AND s.empresa_id = e.empresa_id', 'inner')
            ->join('tipos_equipo te', 'te.id = e.tipo_equipo_id', 'inner')
            ->join('marcas ma', 'ma.id = e.marca_id AND ma.empresa_id = e.empresa_id', 'left')
            ->join('modelos mo', 'mo.id = e.modelo_id AND mo.empresa_id = e.empresa_id', 'left')
            ->where('e.empresa_id', $companyId)
            ->where('e.estado', 'ACTIVO')
            ->where('e.deleted_at', null)
            ->where('s.estado', 1)
            ->where('s.deleted_at', null)
            ->where('te.activo', 1)
            ->orderBy('e.codigo', 'ASC');
        $this->scopeBranches($builder, 'e.sucursal_id', $branchIds);

        $equipment = $builder->get()->getResultArray();
        if ($equipment === []) return [];

        $assigned = [];
        $planRows = $this->database->table('planes_mantenimiento')
            ->select('equipo_id, tipo_servicio_id')
            ->where('empresa_id', $companyId)
            ->where('activo', 1)
            ->where('deleted_at', null)
            ->whereIn('equipo_id', array_column($equipment, 'id'))
            ->get()->getResultArray();
        foreach ($planRows as $plan) $assigned[(int) $plan['equipo_id']][] = (int) $plan['tipo_servicio_id'];
        foreach ($equipment as &$row) $row['assigned_service_type_ids'] = array_values(array_unique($assigned[(int) $row['id']] ?? []));
        unset($row);

        return $equipment;
    }

    public function listTemplateDefaults(int $companyId): array
    {
        return [];
    }

    public function listActiveServiceTypes(int $companyId): array
    {
        return $this->database->table('tipos_servicio')
            ->select('id, codigo, nombre, descripcion, categoria, intervalo_km, intervalo_horas, intervalo_dias, anticipacion_km, anticipacion_horas, anticipacion_dias, prioridad')
            ->where('empresa_id', $companyId)
            ->where('activo', 1)
            ->orderBy('nombre', 'ASC')
            ->get()->getResultArray();
    }

    public function listActiveBranches(int $companyId, ?array $branchIds): array
    {
        $builder = $this->database->table('sucursales')
            ->select('id, codigo, nombre')
            ->where('empresa_id', $companyId)
            ->where('estado', 1)
            ->where('deleted_at', null)
            ->orderBy('nombre', 'ASC');
        $this->scopeBranches($builder, 'id', $branchIds);

        return $builder->get()->getResultArray();
    }

    /** @param array<string,mixed> $row */
    private function hydratePlan(array $row): PlanMantenimiento
    {
        return PlanMantenimiento::reconstituir(
            (int) $row['id'],
            (int) $row['empresa_id'],
            (int) $row['equipo_id'],
            (int) $row['tipo_servicio_id'],
            $row['intervalo_km'] === null ? null : (int) $row['intervalo_km'],
            DecimalHours::toTenths($row['intervalo_horas']),
            $row['intervalo_dias'] === null ? null : (int) $row['intervalo_dias'],
            $row['anticipacion_km'] === null ? null : (int) $row['anticipacion_km'],
            DecimalHours::toTenths($row['anticipacion_horas']),
            $row['anticipacion_dias'] === null ? null : (int) $row['anticipacion_dias'],
            $row['base_km'] === null ? null : (int) $row['base_km'],
            DecimalHours::toTenths($row['base_horas']),
            $row['base_fecha'] === null ? null : new DateTimeImmutable((string) $row['base_fecha']),
            $row['proximo_km'] === null ? null : (int) $row['proximo_km'],
            DecimalHours::toTenths($row['proximas_horas']),
            $row['proxima_fecha'] === null ? null : new DateTimeImmutable((string) $row['proxima_fecha']),
            (string) $row['prioridad'],
            (bool) $row['activo'],
            $row['observaciones'] === null ? null : (string) $row['observaciones'],
            isset($row['origen_plantilla_id']) && $row['origen_plantilla_id'] !== null ? (int) $row['origen_plantilla_id'] : null,
            isset($row['origen_plantilla_item_id']) && $row['origen_plantilla_item_id'] !== null ? (int) $row['origen_plantilla_item_id'] : null,
        );
    }

    /** @param list<int>|null $branchIds */
    private function scopeBranches(BaseBuilder $builder, string $column, ?array $branchIds): void
    {
        if ($branchIds === null) return;
        if ($branchIds === []) {
            $builder->where('1 = 0', null, false);
            return;
        }
        $builder->whereIn($column, $branchIds);
    }
}
