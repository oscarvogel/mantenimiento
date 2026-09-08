<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Application\Employees\EmployeeService;
use App\Application\Identity\ActorContext;
use App\Infrastructure\Employees\CodeIgniterEmployeeAssignmentRepository;
use App\Infrastructure\Employees\CodeIgniterEmployeeRepository;
use App\Infrastructure\Expirations\CodeIgniterExpirationReadModel;
use App\Infrastructure\Identity\SessionActorContext;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use DateTimeImmutable;
use DomainException;
use Throwable;

final class Employees extends BaseController
{
    public function index(): string|ResponseInterface
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

            $historyEmployeeId = $this->nullableIntGet('chofer_id');
            $historyEquipmentSearch = trim((string) $this->request->getGet('movil'));
            $historyFrom = $this->dateGetOrNull('desde');
            $historyTo = $this->dateGetOrNull('hasta');
            $historyStatusRaw = trim((string) $this->request->getGet('vigencia'));
            $historyCurrent = match ($historyStatusRaw) {
                'vigentes' => true,
                'historicas' => false,
                default => null,
            };
            $assignmentRepository = new CodeIgniterEmployeeAssignmentRepository(db_connect());
            $assignmentHistory = $assignmentRepository->assignmentHistory(
                (int) $actor->companyId(),
                $historyEmployeeId,
                $historyEquipmentSearch === '' ? null : $historyEquipmentSearch,
                $historyFrom,
                $historyTo,
                $historyCurrent,
            );
            $employeeCatalog = $this->service()->list($actor, null, null);
            $expirationReadModel = new CodeIgniterExpirationReadModel(db_connect());
            $employeeExpirations = $expirationReadModel->forEmployees(
                (int) $actor->companyId(),
                array_map(static fn (array $row): int => (int) $row['id'], $employees),
            );

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
                    'hasPhoto' => ! empty($row['foto_path']),
                    'photoUrl' => empty($row['foto_path']) ? null : base_url('mantenimiento/empleados/' . (int) $row['id'] . '/foto'),
                    'active' => (int) $row['activo'] === 1,
                    'terminatedAt' => $row['fecha_baja'] ?? null,
                    'terminationReason' => $row['motivo_baja'] ?? null,
                    'importedIncomplete' => (int) ($row['importado_incompleto'] ?? 0) === 1,
                    'updateUrl' => base_url('mantenimiento/empleados/' . (int) $row['id']),
                    'terminateUrl' => base_url('mantenimiento/empleados/' . (int) $row['id'] . '/baja'),
                    'historyUrl' => base_url('mantenimiento/empleados?chofer_id=' . (int) $row['id'] . '#historial-asignaciones'),
                    'expirations' => $employeeExpirations[(int) $row['id']] ?? [],
                ], $employees),
                'assignmentHistory' => array_map(static fn (array $row): array => [
                    'id' => (int) $row['id'],
                    'employeeId' => (int) $row['empleado_id'],
                    'employeeName' => trim((string) $row['empleado_nombre'] . ' ' . (string) ($row['empleado_apellido'] ?? '')),
                    'employeeActive' => (int) ($row['empleado_activo'] ?? 0) === 1,
                    'employeePhotoUrl' => empty($row['empleado_foto_path']) ? null : base_url('mantenimiento/empleados/' . (int) $row['empleado_id'] . '/foto'),
                    'equipmentId' => (int) $row['equipo_id'],
                    'equipmentCode' => (string) $row['equipo_codigo'],
                    'equipmentPlate' => $row['equipo_patente'] ?? null,
                    'branchName' => $row['sucursal_nombre'] ?? null,
                    'startsAt' => (string) $row['fecha_desde'],
                    'endsAt' => $row['fecha_hasta'] ?? null,
                    'current' => empty($row['fecha_hasta']),
                    'notes' => $row['observaciones'] ?? null,
                    'equipmentUrl' => base_url('mantenimiento/equipos/' . (int) $row['equipo_id']),
                ], $assignmentHistory),
                'employeeCatalog' => array_map(static fn (array $row): array => [
                    'id' => (int) $row['id'],
                    'name' => trim((string) $row['nombre'] . ' ' . (string) ($row['apellido'] ?? '')),
                    'active' => (int) $row['activo'] === 1,
                    'photoUrl' => empty($row['foto_path']) ? null : base_url('mantenimiento/empleados/' . (int) $row['id'] . '/foto'),
                ], $employeeCatalog),
                'filters' => [
                    'q' => $search,
                    'status' => $status === '' ? 'activos' : $status,
                ],
                'historyFilters' => [
                    'employeeId' => $historyEmployeeId ?? '',
                    'equipment' => $historyEquipmentSearch,
                    'from' => $historyFrom?->format('Y-m-d') ?? '',
                    'to' => $historyTo?->format('Y-m-d') ?? '',
                    'status' => $historyStatusRaw === '' ? 'todas' : $historyStatusRaw,
                ],
                'expirationTypes' => $expirationReadModel->types(
                    (int) $actor->companyId(),
                    \App\Domain\Expirations\ExpirationSubjectType::EMPLOYEE,
                ),
                'expirationTypeCatalog' => $expirationReadModel->catalog((int) $actor->companyId()),
                'expirationRoutes' => [
                    'create' => base_url('mantenimiento/vencimientos'),
                    'createType' => base_url('mantenimiento/vencimientos/tipos'),
                ],
                'canEdit' => $actor->hasPermission('empleados.editar'),
                'routes' => [
                    'index' => base_url('mantenimiento/empleados'),
                    'create' => base_url('mantenimiento/empleados'),
                ],
            ]);
        } catch (Throwable $exception) {
            if (! $exception instanceof DomainException) {
                log_message('error', 'Falló la carga de empleados: {message}', ['message' => $exception->getMessage()]);
            }

            return $this->response
                ->setStatusCode($exception instanceof DomainException ? 400 : 500)
                ->setHeader('Cache-Control', 'no-store')
                ->setContentType('text/plain')
                ->setBody($exception instanceof DomainException
                    ? $exception->getMessage()
                    : 'No se pudo cargar la gestión de empleados. Revisá que las migraciones del módulo estén aplicadas.');
        }
    }

    public function create(): RedirectResponse
    {
        try {
            $actor = $this->actor();
            $id = $this->service()->create(
                $actor,
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
            $this->storePhotoIfUploaded($actor, $id);

            return redirect()->to('/mantenimiento/empleados')->with('success', "Empleado {$id} creado correctamente.");
        } catch (Throwable $exception) {
            return $this->failure($exception);
        }
    }

    public function update(int $employeeId): RedirectResponse
    {
        try {
            $actor = $this->actor();
            $this->service()->update(
                $actor,
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
            $this->storePhotoIfUploaded($actor, $employeeId);

            return redirect()->to('/mantenimiento/empleados')->with('success', 'Empleado actualizado correctamente.');
        } catch (Throwable $exception) {
            return $this->failure($exception);
        }
    }

    public function photo(int $employeeId): ResponseInterface
    {
        try {
            $actor = $this->actor();
            if (! $actor->hasPermission('empleados.ver')) {
                throw new DomainException('No tenés permiso para ver empleados.');
            }
            $companyId = (int) $actor->companyId();
            $row = db_connect()->table('empleados')
                ->select('foto_path')
                ->where('empresa_id', $companyId)
                ->where('id', $employeeId)
                ->where('deleted_at', null)
                ->get()->getRowArray();

            if ($row === null || empty($row['foto_path'])) {
                throw new DomainException('Foto no disponible.');
            }

            $relative = (string) $row['foto_path'];
            $expectedPrefix = 'empleados/' . $companyId . '/' . $employeeId . '/';
            if (! str_starts_with($relative, $expectedPrefix)) {
                throw new DomainException('Foto no disponible.');
            }

            $path = '/data/priv/' . $relative;
            if (! is_file($path) || ! is_readable($path)) {
                throw new DomainException('Foto no disponible.');
            }

            $mime = mime_content_type($path) ?: 'application/octet-stream';
            return $this->response
                ->setHeader('Content-Disposition', 'inline; filename="foto-empleado"')
                ->setHeader('X-Content-Type-Options', 'nosniff')
                ->setHeader('Cache-Control', 'private, max-age=300')
                ->setContentType($mime)
                ->setBody((string) file_get_contents($path));
        } catch (Throwable $exception) {
            return $this->response
                ->setStatusCode(404)
                ->setHeader('Cache-Control', 'no-store')
                ->setHeader('X-Content-Type-Options', 'nosniff')
                ->setContentType('text/plain')
                ->setBody('Foto no disponible.');
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

    private function storePhotoIfUploaded(ActorContext $actor, int $employeeId): void
    {
        $file = $this->request->getFile('foto');
        if ($file === null || $file->getError() === UPLOAD_ERR_NO_FILE) {
            return;
        }
        if (! $file->isValid()) {
            throw new DomainException('La foto seleccionada no es válida.');
        }
        if ($file->getSize() > 5 * 1024 * 1024) {
            throw new DomainException('La foto no puede superar los 5 MB.');
        }

        $mime = (string) $file->getMimeType();
        $extensions = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ];
        if (! isset($extensions[$mime])) {
            throw new DomainException('La foto debe ser JPG, PNG o WEBP.');
        }

        $companyId = (int) $actor->companyId();
        $database = db_connect();
        $employee = $database->table('empleados')
            ->select('id, foto_path')
            ->where('empresa_id', $companyId)
            ->where('id', $employeeId)
            ->where('deleted_at', null)
            ->get()->getRowArray();
        if ($employee === null) {
            throw new DomainException('El empleado no existe en la empresa.');
        }

        $directory = '/data/priv/empleados/' . $companyId . '/' . $employeeId;
        if (! is_dir($directory) && ! mkdir($directory, 0750, true) && ! is_dir($directory)) {
            throw new DomainException('No se pudo preparar el almacenamiento de la foto.');
        }

        $filename = 'perfil-' . bin2hex(random_bytes(8)) . '.' . $extensions[$mime];
        $file->move($directory, $filename, true);
        $relative = 'empleados/' . $companyId . '/' . $employeeId . '/' . $filename;

        $oldRelative = empty($employee['foto_path']) ? null : (string) $employee['foto_path'];
        $database->table('empleados')
            ->where('empresa_id', $companyId)
            ->where('id', $employeeId)
            ->update([
                'foto_path' => $relative,
                'updated_at' => date('Y-m-d H:i:s'),
                'updated_by' => $actor->userId(),
            ]);

        if ($oldRelative !== null && str_starts_with($oldRelative, 'empleados/' . $companyId . '/' . $employeeId . '/')) {
            $oldPath = '/data/priv/' . $oldRelative;
            if (is_file($oldPath) && $oldPath !== $directory . '/' . $filename) {
                @unlink($oldPath);
            }
        }
    }

    private function nullableIntGet(string $field): ?int
    {
        $value = trim((string) $this->request->getGet($field));
        if ($value === '' || ! ctype_digit($value) || (int) $value <= 0) {
            return null;
        }
        return (int) $value;
    }

    private function dateGetOrNull(string $field): ?DateTimeImmutable
    {
        $value = trim((string) $this->request->getGet($field));
        return $value === '' ? null : new DateTimeImmutable($value);
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
