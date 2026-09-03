<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Application\DriverPortal\GetDriverPortal;
use App\Application\Identity\ActorContext;
use App\Application\WorkRequests\CreateWorkRequest;
use App\Infrastructure\DriverPortal\CodeIgniterDriverPortalReadModel;
use App\Infrastructure\Identity\SessionActorContext;
use App\Infrastructure\WorkRequests\CodeIgniterWorkRequestRepository;
use CodeIgniter\HTTP\ResponseInterface;
use DateTimeImmutable;
use DomainException;
use Throwable;

final class EquipmentOperations extends BaseController
{
    public function show(int $equipmentId): string|\CodeIgniter\HTTP\RedirectResponse
    {
        try {
            $actor = $this->actor();
            $now = new DateTimeImmutable('now');
            $payload = $this->portal()->execute($actor, $equipmentId, $now);
            $payload['recordedAtDefault'] = $now->format('Y-m-d\TH:i');
            $payload['csrf'] = ['name' => csrf_token(), 'hash' => csrf_hash()];
            $payload['routes'] = [
                'submitReading' => base_url('mantenimiento/lecturas/rapidas/fila'),
                'reportIncident' => base_url('mantenimiento/equipos/' . $equipmentId . '/incidencias'),
                'detail' => base_url('mantenimiento/equipos/' . $equipmentId),
                'orders' => base_url('mantenimiento/ordenes?equipo_id=' . $equipmentId),
                'plans' => base_url('mantenimiento/planes?equipo_id=' . $equipmentId),
            ];

            return $this->renderApp(
                $actor,
                'equipment-operate',
                'equipment-operate',
                'Operar equipo',
                $payload,
            );
        } catch (Throwable $exception) {
            if (! $exception instanceof DomainException) {
                log_message('error', 'Falló el portal operativo del equipo: {message}', ['message' => $exception->getMessage()]);
            }

            return redirect()->to('/mantenimiento/equipos')->with(
                'error',
                $exception instanceof DomainException ? $exception->getMessage() : 'No se pudo abrir el equipo.',
            );
        }
    }

    public function reportIncident(int $equipmentId): ResponseInterface
    {
        try {
            $id = $this->workRequests()->execute(
                $this->actor(),
                $equipmentId,
                (string) $this->request->getPost('description'),
                new DateTimeImmutable('now'),
            );

            return $this->response->setStatusCode(201)->setJSON([
                'requestId' => $id,
                'message' => 'Incidencia reportada. Mantenimiento ya puede revisarla.',
                'csrf' => ['name' => csrf_token(), 'hash' => csrf_hash()],
            ]);
        } catch (Throwable $exception) {
            if (! $exception instanceof DomainException) {
                log_message('error', 'Falló el reporte de incidencia: {message}', ['message' => $exception->getMessage()]);
            }

            return $this->response->setStatusCode($exception instanceof DomainException ? 422 : 500)->setJSON([
                'error' => $exception instanceof DomainException ? $exception->getMessage() : 'No se pudo reportar la incidencia.',
                'csrf' => ['name' => csrf_token(), 'hash' => csrf_hash()],
            ]);
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

    private function portal(): GetDriverPortal
    {
        return new GetDriverPortal(new CodeIgniterDriverPortalReadModel(db_connect()));
    }

    private function workRequests(): CreateWorkRequest
    {
        return new CreateWorkRequest(new CodeIgniterWorkRequestRepository(db_connect()));
    }
}
