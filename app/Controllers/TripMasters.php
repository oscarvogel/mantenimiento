<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Application\Employees\EmployeeService;
use App\Application\Identity\ActorContext;
use App\Application\TripMasters\TripMasterCatalog;
use App\Infrastructure\Employees\CodeIgniterEmployeeAssignmentRepository;
use App\Infrastructure\Employees\CodeIgniterEmployeeRepository;
use App\Infrastructure\Identity\SessionActorContext;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use DomainException;
use Throwable;

final class TripMasters extends BaseController
{
    public function index(): string|ResponseInterface
    {
        try {
            $actor = $this->actor();
            $payload = $this->catalog()->page($actor);
            $employees = [];
            if ($actor->hasPermission('empleados.ver')) {
                $employeeService = $this->employeeService();
                $employees = array_map(static fn (array $row): array => [
                    'id' => (int) $row['id'],
                    'name' => trim((string) $row['nombre'] . ' ' . (string) ($row['apellido'] ?? '')),
                ], $employeeService->list($actor, true));
            }
            $payload['employees'] = $employees;
            $payload['urls'] = [
                'create' => base_url('viajes/maestros'),
            ];
            foreach ($payload['catalogs'] as $key => &$catalog) {
                foreach ($catalog['items'] as &$item) {
                    $item['updateUrl'] = base_url('viajes/maestros/' . $key . '/' . (int) $item['id']);
                    $item['statusUrl'] = base_url('viajes/maestros/' . $key . '/' . (int) $item['id'] . '/estado');
                }
                unset($item);
            }
            unset($catalog);

            return $this->renderApp($actor, 'trip-masters', 'trip-masters', 'Maestros de Viajes', $payload);
        } catch (Throwable $exception) {
            return $this->failure($exception);
        }
    }

    public function create(): RedirectResponse
    {
        return $this->save(null);
    }

    public function update(string $catalog, int $id): RedirectResponse
    {
        return $this->save($id, $catalog);
    }

    public function status(string $catalog, int $id): RedirectResponse
    {
        try {
            $active = (string) $this->request->getPost('activo') === '1';
            $this->catalog()->setActive($this->actor(), $catalog, $id, $active);
            return redirect()->to('/viajes/maestros')->with('success', $active ? 'El registro volvió a estar disponible.' : 'El registro se inactivó y su historial quedó conservado.');
        } catch (Throwable $exception) {
            return $this->failure($exception);
        }
    }

    private function save(?int $id, ?string $catalog = null): RedirectResponse
    {
        try {
            $catalog ??= trim((string) $this->request->getPost('catalogo'));
            $this->catalog()->save($this->actor(), $catalog, (array) $this->request->getPost(), $id);
            return redirect()->to('/viajes/maestros')->with('success', $id === null ? 'El maestro se creó correctamente.' : 'Los cambios quedaron guardados.');
        } catch (Throwable $exception) {
            return $this->failure($exception);
        }
    }

    private function catalog(): TripMasterCatalog
    {
        /** @var TripMasterCatalog $catalog */
        $catalog = service('tripMasterCatalog');
        return $catalog;
    }

    private function employeeService(): EmployeeService
    {
        $database = db_connect();
        return new EmployeeService(new CodeIgniterEmployeeRepository($database), new CodeIgniterEmployeeAssignmentRepository($database));
    }

    private function actor(): ActorContext
    {
        $actor = (new SessionActorContext())->current();
        if ($actor === null || $actor->isSuperAdmin() || $actor->companyId() === null) {
            throw new DomainException('Los maestros de Viajes requieren una cuenta de empresa.');
        }
        return $actor;
    }

    private function failure(Throwable $exception): RedirectResponse
    {
        if (! $exception instanceof DomainException) {
            log_message('error', 'Falló la gestión de maestros de Viajes: {message}', ['message' => $exception->getMessage()]);
        }
        return redirect()->to('/viajes/maestros')->withInput()->with('error', $exception instanceof DomainException
            ? $exception->getMessage()
            : 'No se pudo completar la operación sobre los maestros de Viajes.');
    }
}
