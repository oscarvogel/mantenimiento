<?php

declare(strict_types=1);

namespace App\Infrastructure\Importations;

use CodeIgniter\Database\BaseConnection;

final class CodeIgniterPreventiveLibraryReadModel
{
    public function __construct(private readonly BaseConnection $database)
    {
    }

    /** @return array{templates:list<array<string,mixed>>,services:list<array<string,mixed>>} */
    public function overview(int $companyId): array
    {
        if (! $this->database->tableExists('plantillas_mantenimiento')) {
            return ['templates' => [], 'services' => []];
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
        ];
    }
}
