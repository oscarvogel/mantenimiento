<?php

declare(strict_types=1);

namespace App\Infrastructure\Importations;

use App\Application\Importations\Port\PreventiveLibraryTaskCatalogGateway;
use CodeIgniter\Database\BaseConnection;
use RuntimeException;
use Throwable;

final readonly class CodeIgniterPreventiveLibraryTaskCatalogGateway implements PreventiveLibraryTaskCatalogGateway
{
    public function __construct(
        private BaseConnection $database,
    ) {
    }

    public function search(int $companyId, string $query, ?int $serviceTypeId, int $limit = 20): array
    {
        $builder = $this->database->table('tareas_mantenimiento t')
            ->select('t.id, t.codigo, t.nombre, t.activo');

        if ($serviceTypeId !== null) {
            $builder->select('CASE WHEN tst.tarea_id IS NULL THEN 0 ELSE 1 END AS already_linked', false)
                ->join(
                    'tipo_servicio_tareas tst',
                    'tst.tarea_id = t.id AND tst.tipo_servicio_id = ' . (int) $serviceTypeId,
                    'left',
                );
        }

        $rows = $builder
            ->groupStart()
            ->like('t.codigo', $query)
            ->orLike('t.nombre', $query)
            ->groupEnd()
            ->orderBy('t.activo', 'DESC')
            ->orderBy('t.nombre', 'ASC')
            ->limit(max(1, min(50, $limit)))
            ->get()->getResultArray();

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'code' => (string) $row['codigo'],
            'name' => (string) $row['nombre'],
            'active' => (bool) $row['activo'],
            'alreadyLinked' => isset($row['already_linked']) && (bool) $row['already_linked'],
        ], $rows);
    }

    public function serviceBelongsToCompany(int $companyId, int $serviceTypeId): bool
    {
        return $this->database->table('plantilla_mantenimiento_items i')
            ->join('plantillas_mantenimiento p', 'p.id = i.plantilla_id', 'inner')
            ->where('i.tipo_servicio_id', $serviceTypeId)
            ->where('p.empresa_id', $companyId)
            ->where('p.deleted_at', null)
            ->countAllResults() > 0;
    }

    public function findTask(int $taskId): ?array
    {
        $row = $this->database->table('tareas_mantenimiento')
            ->select('id, codigo, nombre, activo')
            ->where('id', $taskId)
            ->get()->getRowArray();

        if ($row === null) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'code' => (string) $row['codigo'],
            'name' => (string) $row['nombre'],
            'active' => (bool) $row['activo'],
        ];
    }

    public function relationExists(int $serviceTypeId, int $taskId): bool
    {
        return $this->database->table('tipo_servicio_tareas')
            ->where('tipo_servicio_id', $serviceTypeId)
            ->where('tarea_id', $taskId)
            ->countAllResults() > 0;
    }

    public function orderIsAvailable(int $serviceTypeId, int $order): bool
    {
        return $this->database->table('tipo_servicio_tareas')
            ->where('tipo_servicio_id', $serviceTypeId)
            ->where('orden', $order)
            ->countAllResults() === 0;
    }

    public function link(int $serviceTypeId, int $taskId, array $relation): void
    {
        $this->database->table('tipo_servicio_tareas')->insert([
            'tipo_servicio_id' => $serviceTypeId,
            'tarea_id' => $taskId,
            'orden' => $relation['order'],
            'obligatoria' => $relation['mandatory'] ? 1 : 0,
            'observaciones' => $relation['observations'],
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        if ($this->database->affectedRows() !== 1) {
            throw new RuntimeException('No se pudo vincular la tarea al servicio.');
        }
    }

    public function findByNormalizedCode(string $normalizedCode): ?array
    {
        $escapedCode = $this->database->escape(mb_strtoupper(trim($normalizedCode)));
        $row = $this->database->table('tareas_mantenimiento')
            ->select('id, codigo, nombre')
            ->where('UPPER(TRIM(codigo)) = ' . $escapedCode, null, false)
            ->get()->getRowArray();

        if ($row === null) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'code' => (string) $row['codigo'],
            'name' => (string) $row['nombre'],
        ];
    }

    public function createAndLink(int $serviceTypeId, array $task, array $relation): int
    {
        $now = date('Y-m-d H:i:s');
        $this->database->transBegin();

        try {
            $this->database->table('tareas_mantenimiento')->insert([
                'codigo' => $task['code'],
                'nombre' => $task['name'],
                'descripcion' => $task['description'],
                'procedimiento' => $task['procedure'],
                'duracion_estimada_min' => $task['durationMinutes'],
                'requiere_repuesto' => $task['requiresPart'] ? 1 : 0,
                'requiere_control' => $task['requiresControl'] ? 1 : 0,
                'requiere_foto' => $task['requiresPhoto'] ? 1 : 0,
                'activo' => $task['active'] ? 1 : 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $taskId = (int) $this->database->insertID();
            if ($taskId < 1) {
                throw new RuntimeException('No se pudo crear la tarea.');
            }

            $this->database->table('tipo_servicio_tareas')->insert([
                'tipo_servicio_id' => $serviceTypeId,
                'tarea_id' => $taskId,
                'orden' => $relation['order'],
                'obligatoria' => $relation['mandatory'] ? 1 : 0,
                'observaciones' => $relation['observations'],
                'created_at' => $now,
            ]);

            if ($this->database->transStatus() === false || ! $this->database->transCommit()) {
                throw new RuntimeException('No se pudo confirmar el alta de la tarea.');
            }

            return $taskId;
        } catch (Throwable $exception) {
            $this->database->transRollback();
            throw $exception;
        }
    }
}
