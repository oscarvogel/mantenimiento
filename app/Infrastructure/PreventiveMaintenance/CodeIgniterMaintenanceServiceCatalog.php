<?php

declare(strict_types=1);

namespace App\Infrastructure\PreventiveMaintenance;

use App\Application\PreventiveMaintenance\Port\MaintenanceServiceCatalog;
use CodeIgniter\Database\BaseConnection;
use DomainException;

final readonly class CodeIgniterMaintenanceServiceCatalog implements MaintenanceServiceCatalog
{
    public function __construct(private BaseConnection $db)
    {
    }

    public function listForCompany(int $companyId): array
    {
        $builder = $this->db->table('tipos_servicio s')
            ->select('s.id, s.empresa_id, s.codigo, s.nombre, s.descripcion, s.categoria, s.intervalo_km, s.intervalo_horas, s.intervalo_dias, s.anticipacion_km, s.anticipacion_horas, s.anticipacion_dias, s.prioridad, s.activo')
            ->select('(SELECT COUNT(*) FROM tipo_servicio_tareas st WHERE st.tipo_servicio_id = s.id) AS tareas_count', false)
            ->select('(SELECT COUNT(*) FROM tipo_servicio_materiales sm WHERE sm.tipo_servicio_id = s.id AND sm.activo = 1) AS materiales_count', false)
            ->groupStart()->where('s.empresa_id', $companyId)->orWhere('s.empresa_id', null)->groupEnd()
            ->orderBy('s.activo', 'DESC')->orderBy('s.nombre', 'ASC');

        $services = array_values(array_map(static function (array $row): array {
            $row['id'] = (int) $row['id'];
            $row['empresa_id'] = $row['empresa_id'] === null ? null : (int) $row['empresa_id'];
            $row['activo'] = (bool) $row['activo'];
            $row['tareas_count'] = (int) $row['tareas_count'];
            $row['materiales_count'] = (int) $row['materiales_count'];
            $row['tasks'] = [];
            return $row;
        }, $builder->get()->getResultArray()));

        if ($services === []) {
            return $services;
        }

        $tasksByService = [];
        $serviceIds = array_column($services, 'id');
        $taskRows = $this->db->table('tipo_servicio_tareas st')
            ->select('st.tipo_servicio_id, st.tarea_id, st.orden, st.obligatoria, st.observaciones, t.codigo, t.nombre, t.activo')
            ->join('tareas_mantenimiento t', 't.id = st.tarea_id', 'inner')
            ->whereIn('st.tipo_servicio_id', $serviceIds)
            ->orderBy('st.tipo_servicio_id', 'ASC')
            ->orderBy('st.orden', 'ASC')
            ->get()->getResultArray();

        foreach ($taskRows as $task) {
            $serviceId = (int) $task['tipo_servicio_id'];
            $tasksByService[$serviceId][] = [
                'id' => (int) $task['tarea_id'],
                'code' => (string) $task['codigo'],
                'name' => (string) $task['nombre'],
                'active' => (bool) $task['activo'],
                'order' => (int) $task['orden'],
                'mandatory' => (bool) $task['obligatoria'],
                'observations' => $task['observaciones'],
            ];
        }

        foreach ($services as &$service) {
            $service['tasks'] = $tasksByService[$service['id']] ?? [];
        }
        unset($service);

        return $services;
    }

    public function create(int $companyId, int $actorId, array $data): int
    {
        $this->ensureCodeAvailable((string) $data['codigo']);
        $now = date('Y-m-d H:i:s');
        $this->db->table('tipos_servicio')->insert($data + [
            'empresa_id' => $companyId,
            'activo' => 1,
            'created_by' => $actorId,
            'updated_by' => $actorId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $id = (int) $this->db->insertID();
        if ($id <= 0) throw new DomainException('No se pudo crear el servicio de mantenimiento.');
        return $id;
    }

    public function update(int $companyId, int $serviceId, int $actorId, array $data): void
    {
        $row = $this->findScoped($companyId, $serviceId);
        $this->ensureCodeAvailable((string) $data['codigo'], $serviceId);
        $payload = $data + ['updated_by' => $actorId, 'updated_at' => date('Y-m-d H:i:s')];
        // Los registros legacy sin empresa se adoptan por la empresa que los edita durante el cutover.
        if ($row['empresa_id'] === null) $payload['empresa_id'] = $companyId;
        $this->db->table('tipos_servicio')->where('id', $serviceId)->update($payload);
    }

    public function setActive(int $companyId, int $serviceId, int $actorId, bool $active): void
    {
        $row = $this->findScoped($companyId, $serviceId);
        $payload = [
            'activo' => $active ? 1 : 0,
            'updated_by' => $actorId,
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        if ($row['empresa_id'] === null) $payload['empresa_id'] = $companyId;
        $this->db->table('tipos_servicio')->where('id', $serviceId)->update($payload);
    }

    private function findScoped(int $companyId, int $serviceId): array
    {
        $row = $this->db->table('tipos_servicio')
            ->select('id, empresa_id, codigo')
            ->where('id', $serviceId)
            ->groupStart()->where('empresa_id', $companyId)->orWhere('empresa_id', null)->groupEnd()
            ->get()->getRowArray();
        if ($row === null) throw new DomainException('El servicio no existe o no pertenece a la empresa activa.');
        return $row;
    }

    private function ensureCodeAvailable(string $code, ?int $exceptId = null): void
    {
        // Mientras exista la restricción legacy global sobre `codigo`, evitamos un error SQL opaco.
        $builder = $this->db->table('tipos_servicio')->where('codigo', $code);
        if ($exceptId !== null) $builder->where('id !=', $exceptId);
        if ($builder->countAllResults() > 0) throw new DomainException('Ya existe un servicio con ese código.');
    }
}
