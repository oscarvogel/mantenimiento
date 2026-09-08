<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Application\Employees\EmployeeService;
use App\Application\Identity\ActorContext;
use App\Infrastructure\Employees\CodeIgniterEmployeeAssignmentRepository;
use App\Infrastructure\Employees\CodeIgniterEmployeeRepository;
use App\Infrastructure\Identity\SessionActorContext;
use CodeIgniter\HTTP\RedirectResponse;
use DateTimeImmutable;
use DomainException;
use Throwable;

final class Employees extends BaseController
{
    public function index(): string|RedirectResponse
    {
        try {
            $actor = $this->actor();
            $status = trim((string) $this->request->getGet('estado'));
            $active = match ($status) {
                'todos' => null,
                'baja' => false,
                default => true,
            };
            $search = trim((string) $this->request->getGet('q'));
            $employees = $this->service()->list($actor, $active, $search === '' ? null : $search);

            return $this->renderApp($actor, 'employees', 'employees-index', 'Empleados y choferes', [
                'employees' => array_map(static fn (array $row): array => [
                    'id' => (int) $row['id'],
                    'firstName' => (string) $row['nombre'],
                    'lastName' => (string) ($row['apellido'] ?? ''),
                    'fullName' => trim((string) $row['nombre'] . ' ' . (string) ($row['apellido'] ?? '')),
                    'document' => $row['documento'] ?? null,
                    'cuil' => $row['cuil'] ?? null,
                    'employeeNumber' => $row['legajo'] ?? null,
                    'phone' => $row['telefono'] ?? null,
                    'email' => $row['email'] ?? null,
                    'hiredAt' => $row['fecha_ingreso'] ?? null,
                    'notes' => $row['observaciones'] ?? null,
                    'active' => (int) $row['activo'] === 1,
                    'terminatedAt' => $row['fecha_baja'] ?? null,
                    'terminationReason' => $row['motivo_baja'] ?? null,
                    'importedIncomplete' => (int) ($row['importado_incompleto'] ?? 0) === 1,
                    'updateUrl' => base_url('mantenimiento/empleados/' . (int) $row['id']),
                    'terminateUrl' => base_url('mantenimiento/empleados/' . (int) $row['id'] . '/baja'),
                ], $employees),
                'filters' => [
                    'q' => $search,
                    'status' => $status === '' ? 'activos' : $status,
                ],
                'canEdit' => $actor->hasPermission('empleados.editar'),
                'routes' => [
                    'index' => base_url('mantenimiento/empleados'),
                    'create' => base_url('mantenimiento/empleados'),
                ],
            ]);
        } catch (Throwable $exception) {
            return $this->failure($exception);
        }
    }

    public function create(): RedirectResponse
    {
        try {
            $id = $this->service()->create(
                $this->actor(),
                (string) $this->request->getPost('nombre'),
                (string) $this->request->getPost('apellido'),
                $this->nullable('documento'),
                $this->nullable('cuil'),
                $this->nullable('legajo'),
                $this->nullable('telefono'),
                $this->nullable('email'),
                $this->dateOrNull('fecha_ingreso'),
                $this->nullable('observaciones'),
            );

            return redirect()->to('/mantenimiento/empleados')->with('success', "Empleado {$id} creado correctamente.");
        } catch (Throwable $exception) {
            return $this->failure($exception);
        }
    }

    public function update(int $employeeId): RedirectResponse
    {
        try {
            $this->service()->update(
                $this->actor(),
                $employeeId,
                (string) $this->request->getPost('nombre'),
                (string) $this->request->getPost('apellido'),
                $this->nullable('documento'),
                $this->nullable('cuil'),
                $this->nullable('legajo'),
                $this->nullable('telefono'),
                $this->nullable('email'),
                $this->dateOrNull('fecha_ingreso'),
                $this->nullable('observaciones'),
            );

            return redirect()->to('/mantenimiento/empleados')->with('success', 'Empleado actualizado correctamente.');
        } catch (Throwable $exception) {
            return $this->failure($exception);
        }
    }

    public function terminate(int $employeeId): RedirectResponse
    {
        try {
            $date = $this->dateOrNull('fecha_baja') ?? new DateTimeImmutable('today');
            $reason = trim((string) $this->request->getPost('motivo_baja'));
            $this->service()->terminate($this->actor(), $employeeId, $date, $reason);

            return redirect()->to('/mantenimiento/empleados')->with('success', 'Empleado dado de baja. Se cerró su asignación vigente conservando el historial.');
        } catch (Throwable $exception) {
            return $this->failure($exception);
        }
    }

    private function service(): EmployeeService
    {
        $database = db_connect();
        return new EmployeeService(
            new CodeIgniterEmployeeRepository($database),
            new CodeIgniterEmployeeAssignmentRepository($database),
        );
    }

    private function actor(): ActorContext
    {
        $actor = (new SessionActorContext())->current();
        if ($actor === null) {
            throw new DomainException('No existe un contexto autenticado válido.');
        }
        return $actor;
    }

    private function nullable(string $field): ?string
    {
        $value = trim((string) $this->request->getPost($field));
        return $value === '' ? null : $value;
    }

    private function dateOrNull(string $field): ?DateTimeImmutable
    {
        $value = trim((string) $this->request->getPost($field));
        return $value === '' ? null : new DateTimeImmutable($value);
    }

    private function failure(Throwable $exception): RedirectResponse
    {
        if (! $exception instanceof DomainException) {
            log_message('error', 'Falló la gestión de empleados: {message}', ['message' => $exception->getMessage()]);
        }

        return redirect()->to('/mantenimiento/empleados')
            ->withInput()
            ->with('error', $exception instanceof DomainException ? $exception->getMessage() : 'No se pudo completar la operación sobre empleados.');
    }
}
