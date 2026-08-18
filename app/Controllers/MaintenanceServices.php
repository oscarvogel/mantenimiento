<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Application\Identity\ActorContext;
use App\Application\PreventiveMaintenance\MaintenanceServiceCatalogService;
use App\Infrastructure\Identity\SessionActorContext;
use App\Infrastructure\PreventiveMaintenance\CodeIgniterMaintenanceServiceCatalog;
use CodeIgniter\HTTP\RedirectResponse;
use DomainException;
use Throwable;

final class MaintenanceServices extends BaseController
{
    public function index(): string
    {
        $actor = $this->actor();
        return $this->renderApp($actor, 'services', 'maintenance-services', 'Servicios de mantenimiento', [
            'services' => $this->catalog()->list($actor),
            'canEdit' => $actor->hasPermission('planes.editar'),
            'urls' => [
                'create' => base_url('mantenimiento/servicios'),
                'base' => base_url('mantenimiento/servicios'),
                'import' => $actor->hasPermission('importaciones.cargar') ? base_url('mantenimiento/importaciones') : null,
            ],
        ]);
    }

    public function create(): RedirectResponse
    {
        try {
            $id = $this->catalog()->create($this->actor(), $this->payload());
            return redirect()->to('/mantenimiento/servicios')->with('success', "Servicio {$id} creado correctamente.");
        } catch (Throwable $exception) {
            return $this->failure($exception, 'No se pudo crear el servicio.');
        }
    }

    public function update(int $serviceId): RedirectResponse
    {
        try {
            $this->catalog()->update($this->actor(), $serviceId, $this->payload());
            return redirect()->to('/mantenimiento/servicios')->with('success', 'Servicio actualizado correctamente.');
        } catch (Throwable $exception) {
            return $this->failure($exception, 'No se pudo actualizar el servicio.');
        }
    }

    public function status(int $serviceId): RedirectResponse
    {
        try {
            $active = filter_var($this->request->getPost('activo'), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            if ($active === null) throw new DomainException('El estado indicado no es válido.');
            $this->catalog()->setActive($this->actor(), $serviceId, $active);
            return redirect()->to('/mantenimiento/servicios')->with('success', $active ? 'Servicio activado.' : 'Servicio inactivado.');
        } catch (Throwable $exception) {
            return $this->failure($exception, 'No se pudo cambiar el estado del servicio.');
        }
    }

    private function payload(): array
    {
        return [
            'codigo' => $this->request->getPost('codigo'),
            'nombre' => $this->request->getPost('nombre'),
            'descripcion' => $this->request->getPost('descripcion'),
            'categoria' => $this->request->getPost('categoria'),
            'intervalo_km' => $this->request->getPost('intervalo_km'),
            'intervalo_horas' => $this->request->getPost('intervalo_horas'),
            'intervalo_dias' => $this->request->getPost('intervalo_dias'),
            'anticipacion_km' => $this->request->getPost('anticipacion_km'),
            'anticipacion_horas' => $this->request->getPost('anticipacion_horas'),
            'anticipacion_dias' => $this->request->getPost('anticipacion_dias'),
            'prioridad' => $this->request->getPost('prioridad'),
        ];
    }

    private function actor(): ActorContext
    {
        $actor = (new SessionActorContext())->current();
        if ($actor === null) throw new DomainException('No existe un contexto autenticado válido.');
        return $actor;
    }

    private function catalog(): MaintenanceServiceCatalogService
    {
        return new MaintenanceServiceCatalogService(new CodeIgniterMaintenanceServiceCatalog(db_connect()));
    }

    private function failure(Throwable $exception, string $fallback): RedirectResponse
    {
        if (! $exception instanceof DomainException) {
            log_message('error', 'Falló el catálogo de servicios: {message}', ['message' => $exception->getMessage()]);
        }
        return redirect()->to('/mantenimiento/servicios')->withInput()->with(
            'error',
            $exception instanceof DomainException ? $exception->getMessage() : $fallback,
        );
    }
}
