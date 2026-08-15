<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Application\Identity\ActorContext;
use App\Application\Importations\ManagePreventiveLibraryTasks;
use App\Infrastructure\Identity\SessionActorContext;
use App\Infrastructure\Importations\CodeIgniterPreventiveLibraryTaskCatalogGateway;
use CodeIgniter\HTTP\ResponseInterface;
use DomainException;
use Throwable;

final class LibraryTaskCatalog extends BaseController
{
    public function search(): ResponseInterface
    {
        try {
            $tasks = $this->manager()->search(
                $this->actor(),
                trim((string) $this->request->getGet('q')),
                $this->optionalPositiveInt($this->request->getGet('tipo_servicio_id'), 'servicio'),
            );

            return $this->response->setJSON(['ok' => true, 'tasks' => $tasks]);
        } catch (Throwable $exception) {
            return $this->jsonFailure($exception);
        }
    }

    public function link(int $serviceTypeId): ResponseInterface
    {
        try {
            $task = $this->manager()->linkExisting(
                $this->actor(),
                $serviceTypeId,
                $this->requiredPositiveInt($this->request->getPost('tarea_id'), 'tarea'),
                $this->requiredPositiveInt($this->request->getPost('orden'), 'orden'),
                $this->checked('obligatoria'),
                $this->nullableString($this->request->getPost('observaciones')),
            );

            return $this->response->setJSON([
                'ok' => true,
                'message' => 'Tarea agregada al servicio.',
                'task' => $task,
            ]);
        } catch (Throwable $exception) {
            return $this->jsonFailure($exception);
        }
    }

    public function createAndLink(int $serviceTypeId): ResponseInterface
    {
        try {
            $task = $this->manager()->createAndLink(
                $this->actor(),
                $serviceTypeId,
                (string) $this->request->getPost('codigo'),
                (string) $this->request->getPost('nombre'),
                $this->nullableString($this->request->getPost('descripcion')),
                $this->nullableString($this->request->getPost('procedimiento')),
                $this->optionalNonNegativeInt($this->request->getPost('duracion_estimada_min'), 'duración estimada'),
                $this->checked('requiere_repuesto'),
                $this->checked('requiere_control'),
                $this->checked('requiere_foto'),
                $this->checked('activo'),
                $this->requiredPositiveInt($this->request->getPost('orden'), 'orden'),
                $this->checked('obligatoria'),
                $this->nullableString($this->request->getPost('observaciones')),
            );

            return $this->response->setJSON([
                'ok' => true,
                'message' => 'Tarea creada y agregada al servicio.',
                'task' => $task,
            ]);
        } catch (Throwable $exception) {
            return $this->jsonFailure($exception);
        }
    }

    private function actor(): ActorContext
    {
        $actor = (new SessionActorContext())->current();
        if ($actor === null) {
            throw new DomainException('No existe un contexto autenticado válido.');
        }
        return $actor;
    }

    private function manager(): ManagePreventiveLibraryTasks
    {
        return new ManagePreventiveLibraryTasks(
            new CodeIgniterPreventiveLibraryTaskCatalogGateway(db_connect()),
        );
    }

    private function requiredPositiveInt(mixed $raw, string $label): int
    {
        $value = $this->optionalPositiveInt($raw, $label);
        if ($value === null) {
            throw new DomainException('Indicá un ' . $label . ' válido.');
        }
        return $value;
    }

    private function optionalPositiveInt(mixed $raw, string $label): ?int
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $value = filter_var($raw, FILTER_VALIDATE_INT);
        if ($value === false || $value < 1) {
            throw new DomainException('Indicá un ' . $label . ' válido.');
        }
        return (int) $value;
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

    private function nullableString(mixed $raw): ?string
    {
        return $raw === null ? null : (string) $raw;
    }

    private function checked(string $field): bool
    {
        return $this->request->getPost($field) !== null;
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
