<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Application\Identity\ActorContext;
use App\Infrastructure\Identity\SessionActorContext;
use CodeIgniter\HTTP\ResponseInterface;
use DomainException;
use RuntimeException;
use Throwable;

final class LibraryTaskCatalog extends BaseController
{
    public function search(): ResponseInterface
    {
        try {
            $actor = $this->editableActor();
            $query = trim((string) $this->request->getGet('q'));
            $serviceTypeId = $this->positiveInt($this->request->getGet('tipo_servicio_id'));

            if ($serviceTypeId !== null) {
                $this->assertOwnedService($actor, $serviceTypeId);
            }

            if (mb_strlen($query) < 2) {
                return $this->response->setJSON(['ok' => true, 'tasks' => []]);
            }

            $database = db_connect();
            $builder = $database->table('tareas_mantenimiento t')
                ->select('t.id, t.codigo, t.nombre, t.activo');

            if ($serviceTypeId !== null) {
                $builder->select('CASE WHEN tst.tarea_id IS NULL THEN 0 ELSE 1 END AS already_linked', false)
                    ->join(
                        'tipo_servicio_tareas tst',
                        'tst.tarea_id = t.id AND tst.tipo_servicio_id = ' . (int) $serviceTypeId,
                        'left',
                    );
            }

            $builder->groupStart()
                ->like('t.codigo', $query)
                ->orLike('t.nombre', $query)
                ->groupEnd()
                ->orderBy('t.activo', 'DESC')
                ->orderBy('t.nombre', 'ASC')
                ->limit(20);

            $rows = $builder->get()->getResultArray();
            $tasks = array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'code' => (string) $row['codigo'],
                'name' => (string) $row['nombre'],
                'active' => (bool) $row['activo'],
                'alreadyLinked' => isset($row['already_linked']) && (bool) $row['already_linked'],
            ], $rows);

            return $this->response->setJSON(['ok' => true, 'tasks' => $tasks]);
        } catch (Throwable $exception) {
            return $this->jsonFailure($exception);
        }
    }

    public function link(int $serviceTypeId): ResponseInterface
    {
        try {
            $actor = $this->editableActor();
            $this->assertOwnedService($actor, $serviceTypeId);

            $taskId = $this->requiredPositiveInt($this->request->getPost('tarea_id'), 'tarea');
            $order = $this->requiredPositiveInt($this->request->getPost('orden'), 'orden');
            $mandatory = $this->checked('obligatoria');
            $observations = $this->limitedObservations($this->request->getPost('observaciones'));

            $database = db_connect();
            $task = $database->table('tareas_mantenimiento')
                ->select('id, codigo, nombre, activo')
                ->where('id', $taskId)
                ->get()->getRowArray();
            if ($task === null) {
                throw new DomainException('La tarea seleccionada no existe.');
            }

            if ($database->table('tipo_servicio_tareas')
                ->where('tipo_servicio_id', $serviceTypeId)
                ->where('tarea_id', $taskId)
                ->countAllResults() > 0) {
                throw new DomainException('La tarea ya está agregada a este servicio.');
            }

            $this->assertOrderAvailable($serviceTypeId, $order);

            $database->table('tipo_servicio_tareas')->insert([
                'tipo_servicio_id' => $serviceTypeId,
                'tarea_id' => $taskId,
                'orden' => $order,
                'obligatoria' => $mandatory ? 1 : 0,
                'observaciones' => $observations,
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            if ($database->affectedRows() !== 1) {
                throw new RuntimeException('No se pudo vincular la tarea al servicio.');
            }

            return $this->response->setJSON([
                'ok' => true,
                'message' => 'Tarea agregada al servicio.',
                'task' => [
                    'id' => $taskId,
                    'code' => (string) $task['codigo'],
                    'name' => (string) $task['nombre'],
                    'active' => (bool) $task['activo'],
                    'order' => $order,
                    'mandatory' => $mandatory,
                    'observations' => $observations,
                ],
            ]);
        } catch (Throwable $exception) {
            return $this->jsonFailure($exception);
        }
    }

    public function createAndLink(int $serviceTypeId): ResponseInterface
    {
        try {
            $actor = $this->editableActor();
            $this->assertOwnedService($actor, $serviceTypeId);

            $code = mb_strtoupper(trim((string) $this->request->getPost('codigo')));
            $name = trim((string) $this->request->getPost('nombre'));
            if ($code === '' || mb_strlen($code) > 50) {
                throw new DomainException('Indicá un código de tarea válido de hasta 50 caracteres.');
            }
            if ($name === '' || mb_strlen($name) > 150) {
                throw new DomainException('Indicá un nombre de tarea válido de hasta 150 caracteres.');
            }

            $description = $this->limitedText($this->request->getPost('descripcion'), 'La descripción');
            $procedure = $this->limitedText($this->request->getPost('procedimiento'), 'El procedimiento');
            $duration = $this->optionalNonNegativeInt($this->request->getPost('duracion_estimada_min'), 'duración estimada');
            $order = $this->requiredPositiveInt($this->request->getPost('orden'), 'orden');
            $mandatory = $this->checked('obligatoria');
            $observations = $this->limitedObservations($this->request->getPost('observaciones'));

            $database = db_connect();
            $duplicate = $database->table('tareas_mantenimiento')
                ->select('id, codigo, nombre')
                ->where('UPPER(TRIM(codigo))', $code, false)
                ->get()->getRowArray();
            if ($duplicate !== null) {
                throw new DomainException(
                    'Ya existe la tarea ' . (string) $duplicate['codigo'] . ' - ' . (string) $duplicate['nombre'] . '. Buscala y agregala como tarea existente.',
                );
            }

            $this->assertOrderAvailable($serviceTypeId, $order);
            $now = date('Y-m-d H:i:s');

            $database->transBegin();
            try {
                $database->table('tareas_mantenimiento')->insert([
                    'codigo' => $code,
                    'nombre' => $name,
                    'descripcion' => $description,
                    'procedimiento' => $procedure,
                    'duracion_estimada_min' => $duration,
                    'requiere_repuesto' => $this->checked('requiere_repuesto') ? 1 : 0,
                    'requiere_control' => $this->checked('requiere_control') ? 1 : 0,
                    'requiere_foto' => $this->checked('requiere_foto') ? 1 : 0,
                    'activo' => $this->checked('activo') ? 1 : 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $taskId = (int) $database->insertID();
                if ($taskId < 1) {
                    throw new RuntimeException('No se pudo crear la tarea.');
                }

                $database->table('tipo_servicio_tareas')->insert([
                    'tipo_servicio_id' => $serviceTypeId,
                    'tarea_id' => $taskId,
                    'orden' => $order,
                    'obligatoria' => $mandatory ? 1 : 0,
                    'observaciones' => $observations,
                    'created_at' => $now,
                ]);

                if ($database->transStatus() === false || ! $database->transCommit()) {
                    throw new RuntimeException('No se pudo confirmar el alta de la tarea.');
                }
            } catch (Throwable $error) {
                $database->transRollback();
                throw $error;
            }

            return $this->response->setJSON([
                'ok' => true,
                'message' => 'Tarea creada y agregada al servicio.',
                'task' => [
                    'id' => $taskId,
                    'code' => $code,
                    'name' => $name,
                    'active' => $this->checked('activo'),
                    'order' => $order,
                    'mandatory' => $mandatory,
                    'observations' => $observations,
                ],
            ]);
        } catch (Throwable $exception) {
            return $this->jsonFailure($exception);
        }
    }

    private function editableActor(): ActorContext
    {
        $actor = (new SessionActorContext())->current();
        if ($actor === null) {
            throw new DomainException('No existe un contexto autenticado válido.');
        }
        if ($actor->isSuperAdmin() || $actor->companyId() === null || ! $actor->hasPermission('importaciones.cargar')) {
            throw new DomainException('No tenes permiso para modificar la biblioteca preventiva.');
        }
        return $actor;
    }

    private function assertOwnedService(ActorContext $actor, int $serviceTypeId): void
    {
        if ($serviceTypeId < 1) {
            throw new DomainException('El servicio indicado no es válido.');
        }

        $row = db_connect()->table('plantilla_mantenimiento_items i')
            ->select('i.id')
            ->join('plantillas_mantenimiento p', 'p.id = i.plantilla_id', 'inner')
            ->where('i.tipo_servicio_id', $serviceTypeId)
            ->where('p.empresa_id', $actor->companyId())
            ->where('p.deleted_at', null)
            ->get()->getRowArray();

        if ($row === null) {
            throw new DomainException('El servicio no pertenece a la biblioteca de esta empresa.');
        }
    }

    private function assertOrderAvailable(int $serviceTypeId, int $order): void
    {
        if (db_connect()->table('tipo_servicio_tareas')
            ->where('tipo_servicio_id', $serviceTypeId)
            ->where('orden', $order)
            ->countAllResults() > 0) {
            throw new DomainException('El orden solicitado ya está ocupado para ese servicio.');
        }
    }

    private function requiredPositiveInt(mixed $raw, string $label): int
    {
        $value = $this->positiveInt($raw);
        if ($value === null) {
            throw new DomainException('Indicá un ' . $label . ' válido.');
        }
        return $value;
    }

    private function positiveInt(mixed $raw): ?int
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $value = filter_var($raw, FILTER_VALIDATE_INT);
        return $value !== false && $value > 0 ? (int) $value : null;
    }

    private function optionalNonNegativeInt(mixed $raw, string $label): ?int
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $value = filter_var($raw, FILTER_VALIDATE_INT);
        if ($value === false || $value < 0) {
            throw new DomainException('Indicá una ' . $label . ' válida.');
        }
        return (int) $value;
    }

    private function checked(string $field): bool
    {
        return $this->request->getPost($field) !== null;
    }

    private function limitedText(mixed $raw, string $label): ?string
    {
        $value = trim((string) ($raw ?? ''));
        if (mb_strlen($value) > 2000) {
            throw new DomainException($label . ' no puede superar 2000 caracteres.');
        }
        return $value === '' ? null : $value;
    }

    private function limitedObservations(mixed $raw): ?string
    {
        $value = trim((string) ($raw ?? ''));
        if (mb_strlen($value) > 500) {
            throw new DomainException('Las observaciones no pueden superar 500 caracteres.');
        }
        return $value === '' ? null : $value;
    }

    private function jsonFailure(Throwable $exception): ResponseInterface
    {
        return $this->response
            ->setStatusCode($exception instanceof DomainException ? 422 : 500)
            ->setJSON([
                'ok' => false,
                'message' => $exception instanceof DomainException
                    ? $exception->getMessage()
                    : 'No se pudo completar la operación sobre la biblioteca.',
            ]);
    }
}
