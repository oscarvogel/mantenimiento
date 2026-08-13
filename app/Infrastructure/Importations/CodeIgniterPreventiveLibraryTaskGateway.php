<?php

declare(strict_types=1);

namespace App\Infrastructure\Importations;

use App\Application\Importations\Port\PreventiveLibraryTaskGateway;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DomainException;
use RuntimeException;
use Throwable;

final class CodeIgniterPreventiveLibraryTaskGateway implements PreventiveLibraryTaskGateway
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * {@inheritDoc}
     * @param array<string,mixed> $fields
     */
    public function update(int $companyId, int $taskId, int $serviceTypeId, array $fields): void
    {
        $relation = $this->findRelationScoped($companyId, $taskId, $serviceTypeId);

        if ($relation === null) {
            throw new DomainException('La tarea no existe o no pertenece a la biblioteca de la empresa.');
        }

        $this->assertOrderAvailable($serviceTypeId, $taskId, (int) $fields['order']);

        $now = date('Y-m-d H:i:s');

        $this->db->transBegin();
        try {
            $this->db->table('tareas_mantenimiento')->where('id', $taskId)->update([
                'nombre' => (string) $fields['name'],
                'descripcion' => $fields['description'] === null || $fields['description'] === '' ? null : (string) $fields['description'],
                'procedimiento' => $fields['procedure'] === null || $fields['procedure'] === '' ? null : (string) $fields['procedure'],
                'duracion_estimada_min' => $fields['durationMinutes'],
                'requiere_repuesto' => $fields['requiresPart'] ? 1 : 0,
                'requiere_control' => $fields['requiresControl'] ? 1 : 0,
                'requiere_foto' => $fields['requiresPhoto'] ? 1 : 0,
                'activo' => $fields['active'] ? 1 : 0,
                'updated_at' => $now,
            ]);

            $this->db->table('tipo_servicio_tareas')
                ->where('tipo_servicio_id', $serviceTypeId)
                ->where('tarea_id', $taskId)
                ->update([
                    'orden' => (int) $fields['order'],
                    'obligatoria' => $fields['mandatory'] ? 1 : 0,
                    'observaciones' => $fields['observations'] === null || $fields['observations'] === '' ? null : (string) $fields['observations'],
                ]);

            if ($this->db->transStatus() === false || ! $this->db->transCommit()) {
                throw new RuntimeException('No se pudo confirmar la actualización de la tarea.');
            }
        } catch (Throwable $error) {
            $this->db->transRollback();
            throw $error;
        }
    }

    /** @return array<string,mixed>|null */
    private function findRelationScoped(int $companyId, int $taskId, int $serviceTypeId): ?array
    {
        return $this->db->table('tipo_servicio_tareas st')
            ->select('st.tipo_servicio_id, st.tarea_id')
            ->join('plantilla_mantenimiento_items i', 'i.tipo_servicio_id = st.tipo_servicio_id', 'inner')
            ->join('plantillas_mantenimiento p', 'p.id = i.plantilla_id', 'inner')
            ->where('st.tipo_servicio_id', $serviceTypeId)
            ->where('st.tarea_id', $taskId)
            ->where('p.empresa_id', $companyId)
            ->where('p.deleted_at', null)
            ->groupBy('st.tipo_servicio_id, st.tarea_id')
            ->get()
            ->getRowArray();
    }

    private function assertOrderAvailable(int $serviceTypeId, int $taskId, int $order): void
    {
        $conflict = $this->db->table('tipo_servicio_tareas')
            ->where('tipo_servicio_id', $serviceTypeId)
            ->where('orden', $order)
            ->where('tarea_id !=', $taskId)
            ->countAllResults();

        if ($conflict > 0) {
            throw new DomainException('El orden solicitado ya esta ocupado para ese servicio.');
        }
    }
}