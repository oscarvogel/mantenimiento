<?php

declare(strict_types=1);

namespace App\Infrastructure\PreventiveMaintenance;

use App\Application\PreventiveMaintenance\Port\MaintenanceServiceCatalog;
use CodeIgniter\Database\BaseConnection;
use DomainException;

final readonly class CodeIgniterMaintenanceServiceCatalog implements MaintenanceServiceCatalog
{
    public function __construct(private BaseConnection $db) {}

    public function listForCompany(int $companyId): array
    {
        $builder = $this->db->table('tipos_servicio s')
            ->select('s.id, s.empresa_id, s.codigo, s.nombre, s.descripcion, s.categoria, s.intervalo_km, s.intervalo_horas, s.intervalo_dias, s.anticipacion_km, s.anticipacion_horas, s.anticipacion_dias, s.prioridad, s.activo')
            ->select('(SELECT COUNT(*) FROM tipo_servicio_tareas st WHERE st.tipo_servicio_id = s.id) AS tareas_count', false)
            ->select('(SELECT COUNT(*) FROM tipo_servicio_materiales sm WHERE sm.tipo_servicio_id = s.id AND sm.tarea_id IS NOT NULL AND sm.activo = 1) AS materiales_count', false)
            ->where('s.empresa_id', $companyId)
            ->orderBy('s.activo', 'DESC')->orderBy('s.nombre', 'ASC');

        $services = array_values(array_map(static function (array $row): array {
            $row['id'] = (int) $row['id'];
            $row['empresa_id'] = (int) $row['empresa_id'];
            $row['activo'] = (bool) $row['activo'];
            $row['tareas_count'] = (int) $row['tareas_count'];
            $row['materiales_count'] = (int) $row['materiales_count'];
            $row['tasks'] = [];
            return $row;
        }, $builder->get()->getResultArray()));

        if ($services === []) return $services;

        $serviceIds = array_column($services, 'id');
        $tasksByService = [];
        $taskRows = $this->db->table('tipo_servicio_tareas st')
            ->select('st.tipo_servicio_id, st.tarea_id, st.orden, st.obligatoria, st.observaciones, t.codigo, t.nombre, t.activo')
            ->join('tareas_mantenimiento t', 't.id = st.tarea_id', 'inner')
            ->whereIn('st.tipo_servicio_id', $serviceIds)
            ->orderBy('st.tipo_servicio_id', 'ASC')->orderBy('st.orden', 'ASC')
            ->get()->getResultArray();

        foreach ($taskRows as $task) {
            $serviceId = (int) $task['tipo_servicio_id'];
            $tasksByService[$serviceId][] = [
                'id' => (int) $task['tarea_id'], 'code' => (string) $task['codigo'], 'name' => (string) $task['nombre'],
                'active' => (bool) $task['activo'], 'order' => (int) $task['orden'], 'mandatory' => (bool) $task['obligatoria'],
                'observations' => $task['observaciones'], 'materials' => [],
            ];
        }

        $materialsByTask = [];
        $materialRows = $this->db->table('tipo_servicio_materiales')
            ->select('id, tipo_servicio_id, tarea_id, descripcion, tipo_item, unidad, cantidad_referencia, cantidad_variable, obligatorio, observaciones, activo')
            ->whereIn('tipo_servicio_id', $serviceIds)
            ->where('tarea_id IS NOT NULL', null, false)
            ->orderBy('tipo_servicio_id', 'ASC')->orderBy('tarea_id', 'ASC')->orderBy('id', 'ASC')
            ->get()->getResultArray();

        foreach ($materialRows as $row) {
            $taskId = (int) $row['tarea_id'];
            $materialsByTask[$taskId][] = [
                'id' => (int) $row['id'], 'taskId' => $taskId, 'description' => (string) $row['descripcion'], 'type' => (string) $row['tipo_item'],
                'unit' => (string) $row['unidad'], 'quantity' => $row['cantidad_referencia'] === null ? null : (string) $row['cantidad_referencia'],
                'variableQuantity' => (bool) $row['cantidad_variable'], 'mandatory' => (bool) $row['obligatorio'],
                'observations' => $row['observaciones'], 'active' => (bool) $row['activo'],
            ];
        }

        foreach ($tasksByService as &$tasks) {
            foreach ($tasks as &$task) $task['materials'] = $materialsByTask[$task['id']] ?? [];
            unset($task);
        }
        unset($tasks);

        foreach ($services as &$service) $service['tasks'] = $tasksByService[$service['id']] ?? [];
        unset($service);
        return $services;
    }

    public function create(int $companyId, int $actorId, array $data): int
    {
        $data['codigo'] = $this->uniqueCode((string) $data['codigo']);
        $now = date('Y-m-d H:i:s');
        $this->db->table('tipos_servicio')->insert($data + [
            'empresa_id' => $companyId, 'activo' => 1, 'created_by' => $actorId, 'updated_by' => $actorId,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $id = (int) $this->db->insertID();
        if ($id <= 0) throw new DomainException('No se pudo crear el servicio de mantenimiento.');
        return $id;
    }

    public function update(int $companyId, int $serviceId, int $actorId, array $data): void
    {
        $this->findScoped($companyId, $serviceId);
        $this->ensureCodeAvailable((string) $data['codigo'], $serviceId);
        $payload = $data + ['updated_by' => $actorId, 'updated_at' => date('Y-m-d H:i:s')];
        $this->db->table('tipos_servicio')->where('id', $serviceId)->where('empresa_id', $companyId)->update($payload);
    }

    public function setActive(int $companyId, int $serviceId, int $actorId, bool $active): void
    {
        $this->findScoped($companyId, $serviceId);
        $this->db->table('tipos_servicio')->where('id', $serviceId)->where('empresa_id', $companyId)->update([
            'activo' => $active ? 1 : 0, 'updated_by' => $actorId, 'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function createMaterial(int $companyId, int $serviceId, int $actorId, array $data): array
    {
        $this->findScoped($companyId, $serviceId);
        $this->assertTaskBelongsToService($serviceId, (int) $data['tarea_id']);
        $now = date('Y-m-d H:i:s');
        $code = 'MAT-' . $serviceId . '-' . strtoupper(substr(hash('sha256', $data['descripcion'] . microtime(true)), 0, 10));
        $this->db->table('tipo_servicio_materiales')->insert([
            'tipo_servicio_id' => $serviceId, 'tarea_id' => $data['tarea_id'], 'codigo' => $code, 'descripcion' => $data['descripcion'],
            'tipo_item' => $data['tipo_item'], 'unidad' => $data['unidad'], 'cantidad_referencia' => $data['cantidad_referencia'],
            'cantidad_variable' => $data['cantidad_variable'] ? 1 : 0, 'codigo_repuesto_catalogo' => null,
            'obligatorio' => $data['obligatorio'] ? 1 : 0, 'observaciones' => $data['observaciones'], 'activo' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $id = (int) $this->db->insertID();
        if ($id <= 0) throw new DomainException('No se pudo agregar el repuesto o insumo.');
        return $this->materialResponse($id, $data, true);
    }

    public function updateMaterial(int $companyId, int $serviceId, int $materialId, int $actorId, array $data): array
    {
        $this->findScoped($companyId, $serviceId);
        $this->findMaterial($serviceId, $materialId);
        $this->assertTaskBelongsToService($serviceId, (int) $data['tarea_id']);
        $this->db->table('tipo_servicio_materiales')->where('id', $materialId)->where('tipo_servicio_id', $serviceId)->update([
            'tarea_id' => $data['tarea_id'], 'descripcion' => $data['descripcion'], 'tipo_item' => $data['tipo_item'], 'unidad' => $data['unidad'],
            'cantidad_referencia' => $data['cantidad_referencia'], 'cantidad_variable' => $data['cantidad_variable'] ? 1 : 0,
            'obligatorio' => $data['obligatorio'] ? 1 : 0, 'observaciones' => $data['observaciones'], 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        return $this->materialResponse($materialId, $data, true);
    }

    public function setMaterialActive(int $companyId, int $serviceId, int $materialId, int $actorId, bool $active): void
    {
        $this->findScoped($companyId, $serviceId);
        $this->findMaterial($serviceId, $materialId);
        $this->db->table('tipo_servicio_materiales')->where('id', $materialId)->where('tipo_servicio_id', $serviceId)->update([
            'activo' => $active ? 1 : 0, 'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function materialResponse(int $id, array $data, bool $active): array
    {
        return ['id' => $id, 'taskId' => (int) $data['tarea_id'], 'description' => $data['descripcion'], 'type' => $data['tipo_item'], 'unit' => $data['unidad'],
            'quantity' => $data['cantidad_referencia'], 'variableQuantity' => $data['cantidad_variable'], 'mandatory' => $data['obligatorio'],
            'observations' => $data['observaciones'], 'active' => $active];
    }

    private function assertTaskBelongsToService(int $serviceId, int $taskId): void
    {
        $exists = $this->db->table('tipo_servicio_tareas')
            ->where('tipo_servicio_id', $serviceId)
            ->where('tarea_id', $taskId)
            ->countAllResults() > 0;
        if (! $exists) throw new DomainException('La tarea no pertenece al servicio indicado.');
    }

    private function findScoped(int $companyId, int $serviceId): array
    {
        $row = $this->db->table('tipos_servicio')->select('id, empresa_id, codigo')->where('id', $serviceId)->where('empresa_id', $companyId)->get()->getRowArray();
        if ($row === null) throw new DomainException('El servicio no existe o no pertenece a la empresa activa.');
        return $row;
    }

    private function findMaterial(int $serviceId, int $materialId): array
    {
        $row = $this->db->table('tipo_servicio_materiales')->where('id', $materialId)->where('tipo_servicio_id', $serviceId)->get()->getRowArray();
        if ($row === null) throw new DomainException('El repuesto o insumo no pertenece al servicio.');
        return $row;
    }

    private function uniqueCode(string $base): string
    {
        if ($this->codeAvailable($base)) return $base;
        for ($suffix = 2; $suffix <= 9999; $suffix++) {
            $tail = '-' . $suffix;
            $candidate = mb_substr($base, 0, 50 - mb_strlen($tail)) . $tail;
            if ($this->codeAvailable($candidate)) return $candidate;
        }
        throw new DomainException('No se pudo generar un código único para el servicio.');
    }

    private function codeAvailable(string $code, ?int $exceptId = null): bool
    {
        $builder = $this->db->table('tipos_servicio')->where('codigo', $code);
        if ($exceptId !== null) $builder->where('id !=', $exceptId);
        return $builder->countAllResults() === 0;
    }

    private function ensureCodeAvailable(string $code, ?int $exceptId = null): void
    {
        if (! $this->codeAvailable($code, $exceptId)) throw new DomainException('Ya existe un servicio con ese código.');
    }
}
