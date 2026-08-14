<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Application\Identity\ActorContext;
use App\Infrastructure\Identity\SessionActorContext;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use DomainException;
use Throwable;

final class LibraryTaskLinks extends BaseController
{
    public function detach(int $taskId): ResponseInterface|RedirectResponse
    {
        try {
            $actor = $this->actor();
            if ($actor->isSuperAdmin() || $actor->companyId() === null || ! $actor->hasPermission('importaciones.cargar')) {
                throw new DomainException('No tenes permiso para modificar la biblioteca preventiva.');
            }

            if ($taskId < 1) {
                throw new DomainException('La tarea indicada no es valida.');
            }

            $database = db_connect();
            $serviceTypeId = $this->positiveIntFromPost(['tipo_servicio_id', 'service_type_id', 'serviceTypeId']);
            $itemId = $this->positiveIntFromPost(['item_id', 'itemId', 'library_item_id', 'libraryItemId']);

            if ($serviceTypeId === null && $itemId !== null) {
                $item = $database->table('plantilla_mantenimiento_items i')
                    ->select('i.tipo_servicio_id')
                    ->join('plantillas_mantenimiento p', 'p.id = i.plantilla_id', 'inner')
                    ->where('i.id', $itemId)
                    ->where('p.empresa_id', $actor->companyId())
                    ->where('p.deleted_at', null)
                    ->get()->getRowArray();

                if ($item === null) {
                    throw new DomainException('El servicio de biblioteca no existe o pertenece a otra empresa.');
                }

                $serviceTypeId = (int) $item['tipo_servicio_id'];
            }

            if ($serviceTypeId === null) {
                $ownedServices = $database->table('plantilla_mantenimiento_items i')
                    ->select('i.tipo_servicio_id')
                    ->join('plantillas_mantenimiento p', 'p.id = i.plantilla_id', 'inner')
                    ->join('tipo_servicio_tareas tst', 'tst.tipo_servicio_id = i.tipo_servicio_id', 'inner')
                    ->where('tst.tarea_id', $taskId)
                    ->where('p.empresa_id', $actor->companyId())
                    ->where('p.deleted_at', null)
                    ->groupBy('i.tipo_servicio_id')
                    ->get()->getResultArray();

                if (count($ownedServices) === 1) {
                    $serviceTypeId = (int) $ownedServices[0]['tipo_servicio_id'];
                } elseif ($ownedServices === []) {
                    throw new DomainException('La tarea no esta asociada a ningun servicio de esta biblioteca.');
                } else {
                    throw new DomainException('Debe indicar el servicio del que quiere quitar la tarea.');
                }
            }

            $ownedItem = $database->table('plantilla_mantenimiento_items i')
                ->select('i.id')
                ->join('plantillas_mantenimiento p', 'p.id = i.plantilla_id', 'inner')
                ->where('i.tipo_servicio_id', $serviceTypeId)
                ->where('p.empresa_id', $actor->companyId())
                ->where('p.deleted_at', null)
                ->get()->getRowArray();

            if ($ownedItem === null) {
                throw new DomainException('El servicio no pertenece a la biblioteca de esta empresa.');
            }

            $link = $database->table('tipo_servicio_tareas')
                ->select('tipo_servicio_id, tarea_id')
                ->where('tipo_servicio_id', $serviceTypeId)
                ->where('tarea_id', $taskId)
                ->get()->getRowArray();

            if ($link === null) {
                throw new DomainException('La tarea ya no esta asociada a este servicio.');
            }

            $database->table('tipo_servicio_tareas')
                ->where('tipo_servicio_id', $serviceTypeId)
                ->where('tarea_id', $taskId)
                ->delete();

            if ($database->affectedRows() !== 1) {
                throw new DomainException('No se pudo quitar la tarea del servicio.');
            }

            if ($this->wantsJson()) {
                return $this->response->setJSON([
                    'ok' => true,
                    'taskId' => $taskId,
                    'serviceTypeId' => $serviceTypeId,
                    'message' => 'Tarea quitada del servicio.',
                ]);
            }

            return redirect()->to('/mantenimiento/importaciones/biblioteca')
                ->with('success', 'Tarea quitada del servicio.');
        } catch (Throwable $exception) {
            if ($this->wantsJson()) {
                return $this->response
                    ->setStatusCode($exception instanceof DomainException ? 422 : 500)
                    ->setJSON([
                        'ok' => false,
                        'message' => $exception instanceof DomainException
                            ? $exception->getMessage()
                            : 'No se pudo completar la operacion sobre la biblioteca.',
                    ]);
            }

            return $this->failure($exception, '/mantenimiento/importaciones/biblioteca');
        }
    }

    private function actor(): ActorContext
    {
        $actor = (new SessionActorContext())->current();
        if ($actor === null) {
            throw new DomainException('No existe un contexto autenticado valido.');
        }

        return $actor;
    }

    /** @param list<string> $fields */
    private function positiveIntFromPost(array $fields): ?int
    {
        foreach ($fields as $field) {
            $raw = $this->request->getPost($field);
            if ($raw === null || $raw === '') {
                continue;
            }

            $value = filter_var($raw, FILTER_VALIDATE_INT);
            if ($value !== false && $value > 0) {
                return (int) $value;
            }
        }

        return null;
    }

    private function wantsJson(): bool
    {
        $accept = strtolower($this->request->getHeaderLine('Accept'));
        $requestedWith = strtolower($this->request->getHeaderLine('X-Requested-With'));

        return str_contains($accept, 'application/json') || $requestedWith === 'xmlhttprequest';
    }
}
