<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

final class VerticalCircuitSeeder extends Seeder
{
    public function run(): void
    {
        $now = date('Y-m-d H:i:s');

        $this->ensureRow('tipos_equipo', 'nombre', 'Camión', [
            'nombre' => 'Camión', 'controla_km' => 1, 'controla_horas' => 0,
            'activo' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->ensureRow('tipos_equipo', 'nombre', 'Máquina', [
            'nombre' => 'Máquina', 'controla_km' => 0, 'controla_horas' => 1,
            'activo' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->ensureRow('tipos_equipo', 'nombre', 'Unidad mixta', [
            'nombre' => 'Unidad mixta', 'controla_km' => 1, 'controla_horas' => 1,
            'activo' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);

        $company = $this->db->table('empresas')->select('id')->where('cuit', '30-12345678-9')->get()->getRowArray();
        if ($company === null) {
            return;
        }
        $companyId = (int) $company['id'];

        $serviceTypeId = $this->ensureService($companyId, 'CAMBIO-ACEITE', [
            'empresa_id' => $companyId,
            'codigo' => 'CAMBIO-ACEITE',
            'nombre' => 'Cambio de aceite y filtros',
            'descripcion' => 'Servicio preventivo base para probar el primer circuito vertical.',
            'intervalo_km' => 10000,
            'anticipacion_km' => 1000,
            'prioridad' => 'MEDIA',
            'activo' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $taskId = $this->ensureRow('tareas_mantenimiento', 'codigo', 'ACEITE-FILTROS', [
            'codigo' => 'ACEITE-FILTROS', 'nombre' => 'Cambiar aceite y filtros',
            'descripcion' => 'Renovar aceite y filtros según el procedimiento del equipo.',
            'duracion_estimada_min' => 90, 'requiere_repuesto' => 1, 'requiere_control' => 1,
            'requiere_foto' => 0, 'activo' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        if (! $this->db->table('tipo_servicio_tareas')->where(['tipo_servicio_id' => $serviceTypeId, 'tarea_id' => $taskId])->countAllResults()) {
            $this->db->table('tipo_servicio_tareas')->insert([
                'tipo_servicio_id' => $serviceTypeId, 'tarea_id' => $taskId, 'orden' => 1,
                'obligatoria' => 1, 'created_at' => $now,
            ]);
        }
    }

    /** @param array<string, mixed> $data */
    private function ensureRow(string $table, string $key, mixed $value, array $data): int
    {
        $existing = $this->db->table($table)->select('id')->where($key, $value)->get()->getRowArray();
        if ($existing !== null) {
            return (int) $existing['id'];
        }

        $this->db->table($table)->insert($data);

        return (int) $this->db->insertID();
    }

    /** @param array<string, mixed> $data */
    private function ensureService(int $companyId, string $code, array $data): int
    {
        $existing = $this->db->table('tipos_servicio')
            ->select('id')
            ->where('empresa_id', $companyId)
            ->where('codigo', $code)
            ->get()->getRowArray();
        if ($existing !== null) {
            return (int) $existing['id'];
        }

        $this->db->table('tipos_servicio')->insert($data);
        return (int) $this->db->insertID();
    }
}
