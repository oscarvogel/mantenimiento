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
            ->select('e.id, e.codigo, e.patente, e.sucursal_id, e.tipo_equipo_id, e.km_actual, e.horas_actuales, s.codigo sucursal_codigo, s.nombre sucursal_nombre, te.nombre tipo_nombre, te.controla_km, te.controla_horas')
            ->join('sucursales s', 's.id = e.sucursal_id AND s.empresa_id = e.empresa_id', 'inner')
            ->join('tipos_equipo te', 'te.id = e.tipo_equipo_id', 'inner')
            ->where('e.empresa_id', $companyId)
            ->where('e.estado', 'ACTIVO')
            ->where('e.deleted_at', null)
            ->where('s.estado', 1)
            ->where('s.deleted_at', null)
            ->where('te.activo', 1)
            ->orderBy('e.codigo', 'ASC');
        $this->scopeBranches($builder, 'e.sucursal_id', $branchIds);

        return $builder->get()->getResultArray();
    }

    public function listTemplateDefaults(int $companyId): array
    {
        if (! $this->database->tableExists('plantillas_mantenimiento') || ! $this->database->tableExists('plantilla_mantenimiento_items')) {
            return [];
        }

        $rows = $this->database->table('plantilla_mantenimiento_items i')
            ->select('i.id, i.plantilla_id, i.tipo_servicio_id, i.intervalo_km, i.intervalo_horas, i.intervalo_dias, i.anticipacion_km, i.anticipacion_horas, i.anticipacion_dias, i.prioridad, i.observaciones, p.nombre plantilla_nombre, p.tipo_equipo_id, te.nombre tipo_equipo_nombre, ts.nombre servicio_nombre')
            ->join('plantillas_mantenimiento p', 'p.id = i.plantilla_id', 'inner')
            ->join('tipos_equipo te', 'te.id = p.tipo_equipo_id', 'inner')
            ->join('tipos_servicio ts', 'ts.id = i.tipo_servicio_id', 'inner')
            ->where('p.empresa_id', $companyId)
            ->where('p.activo', 1)
            ->where('p.deleted_at', null)
            ->where('i.activo', 1)
            ->where('ts.activo', 1)
            ->orderBy('p.nombre', 'ASC')
            ->orderBy('ts.nombre', 'ASC')
            ->get()->getResultArray();

        return array_map(fn (array $row): array => [
            'id' => (int) $row['id'],
            'template_id' => (int) $row['plantilla_id'],
            'template_name' => (string) $row['plantilla_nombre'],
            'equipment_type_id' => (int) $row['tipo_equipo_id'],
            'equipment_type_name' => (string) $row['tipo_equipo_nombre'],
            'service_type_id' => (int) $row['tipo_servicio_id'],
            'service_name' => (string) $row['servicio_nombre'],
            'interval_km' => $row['intervalo_km'] === null ? null : (int) $row['intervalo_km'],
            'interval_hours' => $this->decimalHours(DecimalHours::toTenths($row['intervalo_horas'])),
            'interval_days' => $row['intervalo_dias'] === null ? null : (int) $row['intervalo_dias'],
            'warning_km' => $row['anticipacion_km'] === null ? null : (int) $row['anticipacion_km'],
            'warning_hours' => $this->decimalHours(DecimalHours::toTenths($row['anticipacion_horas'])),
            'warning_days' => $row['anticipacion_dias'] === null ? null : (int) $row['anticipacion_dias'],
            'priority' => (string) $row['prioridad'],
            'notes' => $row['observaciones'] === null ? null : (string) $row['observaciones'],
        ], $rows);
    }

    public function listActiveServiceTypes(): array
    {
        return $this->database->table('tipos_servicio')
            ->select('id, codigo, nombre')
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
        );
    }

    private function decimalHours(?int $tenths): ?string
    {
        return $tenths === null ? null : number_format($tenths / 10, 1, '.', '');
    }

    /** @param list<int>|null $branchIds */
    private function scopeBranches(BaseBuilder $builder, string $column, ?array $branchIds): void
    {
        if ($branchIds === null) {
            return;
        }
        if ($branchIds === []) {
            $builder->where('1 = 0', null, false);
            return;
        }
        $builder->whereIn($column, $branchIds);
    }
}
