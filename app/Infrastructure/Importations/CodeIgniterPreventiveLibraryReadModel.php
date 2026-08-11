<?php

declare(strict_types=1);

namespace App\Infrastructure\Importations;

use App\Infrastructure\PreventiveMaintenance\DecimalHours;
use CodeIgniter\Database\BaseConnection;

final class CodeIgniterPreventiveLibraryReadModel
{
    public function __construct(private readonly BaseConnection $database)
    {
    }

    /** @return array{templates:list<array<string,mixed>>,services:list<array<string,mixed>>,items:list<array<string,mixed>>} */
    public function overview(int $companyId): array
    {
        if (! $this->database->tableExists('plantillas_mantenimiento') || ! $this->database->tableExists('plantilla_mantenimiento_items')) {
            return ['templates' => [], 'services' => [], 'items' => []];
        }

        $templates = $this->database->table('plantillas_mantenimiento p')
            ->select('p.id, p.codigo, p.nombre, p.ambito, p.marca, p.modelo, p.activo, te.nombre tipo_equipo, COUNT(i.id) item_count')
            ->join('tipos_equipo te', 'te.id = p.tipo_equipo_id', 'left')
            ->join('plantilla_mantenimiento_items i', 'i.plantilla_id = p.id AND i.activo = 1', 'left')
            ->where('p.empresa_id', $companyId)
            ->where('p.deleted_at', null)
            ->groupBy('p.id, p.codigo, p.nombre, p.ambito, p.marca, p.modelo, p.activo, te.nombre')
            ->orderBy('p.nombre', 'ASC')
            ->get()->getResultArray();

        $services = $this->database->table('tipos_servicio s')
            ->select('s.id, s.codigo, s.nombre, s.categoria, s.activo, COUNT(DISTINCT st.tarea_id) task_count, COUNT(DISTINCT sm.id) material_count')
            ->join('tipo_servicio_tareas st', 'st.tipo_servicio_id = s.id', 'left')
            ->join('tipo_servicio_materiales sm', 'sm.tipo_servicio_id = s.id AND sm.activo = 1', 'left')
            ->groupBy('s.id, s.codigo, s.nombre, s.categoria, s.activo')
            ->orderBy('s.nombre', 'ASC')
            ->get()->getResultArray();

        $items = $this->database->table('plantilla_mantenimiento_items i')
            ->select('i.id, i.plantilla_id, i.tipo_servicio_id, i.intervalo_km, i.intervalo_horas, i.intervalo_dias, i.anticipacion_km, i.anticipacion_horas, i.anticipacion_dias, i.prioridad, i.activo, i.observaciones, p.codigo plantilla_codigo, p.nombre plantilla_nombre, te.nombre tipo_equipo, s.codigo servicio_codigo, s.nombre servicio_nombre')
            ->join('plantillas_mantenimiento p', 'p.id = i.plantilla_id', 'inner')
            ->join('tipos_equipo te', 'te.id = p.tipo_equipo_id', 'inner')
            ->join('tipos_servicio s', 's.id = i.tipo_servicio_id', 'inner')
            ->where('p.empresa_id', $companyId)
            ->where('p.deleted_at', null)
            ->orderBy('p.nombre', 'ASC')
            ->orderBy('s.nombre', 'ASC')
            ->get()->getResultArray();

        return [
            'templates' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'code' => (string) $row['codigo'],
                'name' => (string) $row['nombre'],
                'scope' => (string) $row['ambito'],
                'equipmentType' => (string) ($row['tipo_equipo'] ?? ''),
                'brand' => $row['marca'],
                'model' => $row['modelo'],
                'active' => (int) $row['activo'] === 1,
                'itemCount' => (int) $row['item_count'],
            ], $templates),
            'services' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'code' => (string) $row['codigo'],
                'name' => (string) $row['nombre'],
                'category' => (string) ($row['categoria'] ?? ''),
                'active' => (int) $row['activo'] === 1,
                'taskCount' => (int) $row['task_count'],
                'materialCount' => (int) $row['material_count'],
            ], $services),
            'items' => array_map(fn (array $row): array => [
                'id' => (int) $row['id'],
                'templateId' => (int) $row['plantilla_id'],
                'templateCode' => (string) $row['plantilla_codigo'],
                'templateName' => (string) $row['plantilla_nombre'],
                'equipmentType' => (string) $row['tipo_equipo'],
                'serviceTypeId' => (int) $row['tipo_servicio_id'],
                'serviceCode' => (string) $row['servicio_codigo'],
                'serviceName' => (string) $row['servicio_nombre'],
                'intervalKm' => $row['intervalo_km'] === null ? null : (int) $row['intervalo_km'],
                'intervalHours' => DecimalHours::fromTenths(DecimalHours::toTenths($row['intervalo_horas'])),
                'intervalDays' => $row['intervalo_dias'] === null ? null : (int) $row['intervalo_dias'],
                'warningKm' => $row['anticipacion_km'] === null ? null : (int) $row['anticipacion_km'],
                'warningHours' => DecimalHours::fromTenths(DecimalHours::toTenths($row['anticipacion_horas'])),
                'warningDays' => $row['anticipacion_dias'] === null ? null : (int) $row['anticipacion_dias'],
                'priority' => (string) $row['prioridad'],
                'active' => (int) $row['activo'] === 1,
                'notes' => $row['observaciones'] === null ? null : (string) $row['observaciones'],
            ], $items),
        ];
    }
}
