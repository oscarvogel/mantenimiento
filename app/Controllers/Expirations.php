<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Application\Identity\ActorContext;
use App\Domain\Expirations\ExpirationSubjectType;
use App\Infrastructure\Identity\SessionActorContext;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use DateTimeImmutable;
use DomainException;
use Throwable;

final class Expirations extends BaseController
{
    public function index(): string|ResponseInterface
    {
        try {
            $actor = $this->actor();
            $canSeeEquipment = $actor->hasPermission('equipos.ver');
            $canSeeEmployees = $actor->hasPermission('empleados.ver');
            if (! $canSeeEquipment && ! $canSeeEmployees) {
                throw new DomainException('No tenés permiso para consultar vencimientos.');
            }

            $subject = mb_strtoupper(trim((string) $this->request->getGet('tipo')));
            if (! in_array($subject, ['TODOS', 'EQUIPO', 'EMPLEADO'], true)) {
                $subject = 'TODOS';
            }
            if (! $canSeeEquipment) {
                $subject = 'EMPLEADO';
            } elseif (! $canSeeEmployees) {
                $subject = 'EQUIPO';
            }
            $status = mb_strtolower(trim((string) $this->request->getGet('estado')));
            if (! in_array($status, ['todos', 'vencidos', '7', '15', '30', 'vigentes'], true)) {
                $status = 'todos';
            }
            $branchId = (int) $this->request->getGet('sucursal_id');
            $search = trim((string) $this->request->getGet('q'));

            $allowedBranches = $actor->hasAllCompanyBranches() ? null : $actor->branchIds();
            if ($branchId > 0 && $allowedBranches !== null && ! in_array($branchId, $allowedBranches, true)) {
                throw new DomainException('No tenés acceso a la sucursal seleccionada.');
            }

            $readModel = new \App\Infrastructure\Expirations\CodeIgniterExpirationReadModel(db_connect());
            $items = $readModel->upcoming((int) $actor->companyId(), [
                'subject' => $subject,
                'status' => $status,
                'branchId' => $branchId > 0 ? $branchId : null,
                'q' => $search,
            ], $allowedBranches);

            $summary = [
                'total' => count($items),
                'overdue' => count(array_filter($items, static fn (array $item): bool => (int) $item['daysUntil'] < 0)),
                'next7' => count(array_filter($items, static fn (array $item): bool => (int) $item['daysUntil'] >= 0 && (int) $item['daysUntil'] <= 7)),
                'next30' => count(array_filter($items, static fn (array $item): bool => (int) $item['daysUntil'] >= 0 && (int) $item['daysUntil'] <= 30)),
            ];

            return $this->renderApp($actor, 'expirations', 'expirations-index', 'Próximos vencimientos', [
                'items' => $items,
                'summary' => $summary,
                'branches' => $readModel->branches((int) $actor->companyId(), $allowedBranches),
                'filters' => [
                    'subject' => $subject,
                    'status' => $status,
                    'branchId' => $branchId > 0 ? $branchId : '',
                    'q' => $search,
                ],
                'routes' => [
                    'index' => base_url('mantenimiento/vencimientos'),
                    'types' => base_url('mantenimiento/maestros/vencimientos'),
                ],
                'canSeeEquipment' => $canSeeEquipment,
                'canSeeEmployees' => $canSeeEmployees,
                'canManageTypes' => $actor->hasPermission('equipos.editar') || $actor->hasPermission('empleados.editar'),
            ]);
        } catch (Throwable $exception) {
            if (! $exception instanceof DomainException) {
                log_message('error', 'Falló la consulta de próximos vencimientos: {message}', ['message' => $exception->getMessage()]);
            }

            return $this->response
                ->setStatusCode($exception instanceof DomainException ? 403 : 500)
                ->setHeader('Cache-Control', 'no-store')
                ->setContentType('text/plain')
                ->setBody($exception instanceof DomainException ? $exception->getMessage() : 'No se pudieron cargar los próximos vencimientos.');
        }
    }

    public function typesIndex(): string|ResponseInterface
    {
        try {
            $actor = $this->actor();
            $this->assertCanEditAny($actor);
            $readModel = new \App\Infrastructure\Expirations\CodeIgniterExpirationReadModel(db_connect());

            return $this->renderApp($actor, 'masters-expirations', 'expiration-types-master', 'Tipos de vencimiento', [
                'types' => $readModel->catalog((int) $actor->companyId()),
                'routes' => [
                    'create' => base_url('mantenimiento/vencimientos/tipos'),
                    'index' => base_url('mantenimiento/maestros/vencimientos'),
                ],
            ]);
        } catch (Throwable $exception) {
            if (! $exception instanceof DomainException) {
                log_message('error', 'Falló la carga del maestro de vencimientos: {message}', ['message' => $exception->getMessage()]);
            }

            return $this->response
                ->setStatusCode($exception instanceof DomainException ? 403 : 500)
                ->setHeader('Cache-Control', 'no-store')
                ->setContentType('text/plain')
                ->setBody($exception instanceof DomainException ? $exception->getMessage() : 'No se pudo cargar el maestro de tipos de vencimiento.');
        }
    }

    public function updateType(int $typeId): RedirectResponse
    {
        $returnTo = $this->returnTo();
        try {
            $actor = $this->actor();
            $this->assertCanEditAny($actor);
            $companyId = (int) $actor->companyId();
            $db = db_connect();

            $current = $db->table('tipos_vencimiento')
                ->select('id')
                ->where('empresa_id', $companyId)
                ->where('id', $typeId)
                ->where('deleted_at', null)
                ->get()->getRowArray();
            if ($current === null) {
                throw new DomainException('El tipo de vencimiento no existe.');
            }

            $name = trim((string) $this->request->getPost('nombre'));
            if ($name === '' || mb_strlen($name) > 100) {
                throw new DomainException('El nombre del tipo es obligatorio y admite hasta 100 caracteres.');
            }
            $appliesTo = mb_strtoupper(trim((string) $this->request->getPost('aplica_a')));
            if (! in_array($appliesTo, ['EQUIPO', 'EMPLEADO', 'AMBOS'], true)) {
                throw new DomainException('El tipo debe aplicar a EQUIPO, EMPLEADO o AMBOS.');
            }
            $warningDays = (int) $this->request->getPost('dias_aviso_previo');
            if ($warningDays < 0 || $warningDays > 3650) {
                throw new DomainException('Los días de aviso previo deben estar entre 0 y 3650.');
            }

            $duplicate = $db->table('tipos_vencimiento')
                ->where('empresa_id', $companyId)
                ->where('nombre', $name)
                ->where('id !=', $typeId)
                ->where('deleted_at', null)
                ->countAllResults() > 0;
            if ($duplicate) {
                throw new DomainException('Ya existe un tipo de vencimiento con ese nombre.');
            }

            $db->table('tipos_vencimiento')
                ->where('empresa_id', $companyId)
                ->where('id', $typeId)
                ->update([
                    'nombre' => $name,
                    'aplica_a' => $appliesTo,
                    'descripcion' => $this->nullable('descripcion', 500),
                    'dias_aviso_previo' => $warningDays,
                    'requiere_documento' => $this->request->getPost('requiere_documento') === null ? 0 : 1,
                    'updated_by' => $actor->userId(),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);

            return redirect()->to($returnTo)->with('success', 'Tipo de vencimiento actualizado.');
        } catch (Throwable $exception) {
            return $this->failure($exception, $returnTo);
        }
    }

    public function createType(): RedirectResponse
    {
        $returnTo = $this->returnTo();
        try {
            $actor = $this->actor();
            $this->assertCanEditAny($actor);

            $name = trim((string) $this->request->getPost('nombre'));
            if ($name === '' || mb_strlen($name) > 100) {
                throw new DomainException('El nombre del tipo es obligatorio y admite hasta 100 caracteres.');
            }

            $appliesTo = mb_strtoupper(trim((string) $this->request->getPost('aplica_a')));
            if (! in_array($appliesTo, ['EQUIPO', 'EMPLEADO', 'AMBOS'], true)) {
                throw new DomainException('El tipo debe aplicar a EQUIPO, EMPLEADO o AMBOS.');
            }

            $warningDays = (int) $this->request->getPost('dias_aviso_previo');
            if ($warningDays < 0 || $warningDays > 3650) {
                throw new DomainException('Los días de aviso previo deben estar entre 0 y 3650.');
            }

            $companyId = (int) $actor->companyId();
            $db = db_connect();
            if ($db->table('tipos_vencimiento')
                ->where('empresa_id', $companyId)
                ->where('nombre', $name)
                ->where('deleted_at', null)
                ->countAllResults() > 0) {
                throw new DomainException('Ya existe un tipo de vencimiento con ese nombre.');
            }

            $now = date('Y-m-d H:i:s');
            $db->table('tipos_vencimiento')->insert([
                'empresa_id' => $companyId,
                'nombre' => $name,
                'aplica_a' => $appliesTo,
                'descripcion' => $this->nullable('descripcion', 500),
                'dias_aviso_previo' => $warningDays,
                'requiere_documento' => $this->request->getPost('requiere_documento') === null ? 0 : 1,
                'activo' => 1,
                'created_by' => $actor->userId(),
                'updated_by' => $actor->userId(),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return redirect()->to($returnTo)->with('success', 'Tipo de vencimiento creado.');
        } catch (Throwable $exception) {
            return $this->failure($exception, $returnTo);
        }
    }

    public function toggleType(int $typeId): RedirectResponse
    {
        $returnTo = $this->returnTo();
        try {
            $actor = $this->actor();
            $this->assertCanEditAny($actor);
            $companyId = (int) $actor->companyId();
            $db = db_connect();

            $row = $db->table('tipos_vencimiento')
                ->select('id, activo')
                ->where('empresa_id', $companyId)
                ->where('id', $typeId)
                ->where('deleted_at', null)
                ->get()->getRowArray();

            if ($row === null) {
                throw new DomainException('El tipo de vencimiento no existe.');
            }

            $db->table('tipos_vencimiento')
                ->where('empresa_id', $companyId)
                ->where('id', $typeId)
                ->update([
                    'activo' => (int) $row['activo'] === 1 ? 0 : 1,
                    'updated_by' => $actor->userId(),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);

            return redirect()->to($returnTo)->with('success', 'Estado del tipo de vencimiento actualizado.');
        } catch (Throwable $exception) {
            return $this->failure($exception, $returnTo);
        }
    }

    public function create(): RedirectResponse
    {
        $returnTo = $this->returnTo();
        try {
            $actor = $this->actor();
            $subjectType = $this->subjectType();
            $this->assertCanEdit($actor, $subjectType);

            $companyId = (int) $actor->companyId();
            $subjectId = $this->positiveInt('sujeto_id', 'sujeto');
            $typeId = $this->positiveInt('tipo_vencimiento_id', 'tipo de vencimiento');
            $expiresAt = $this->requiredDate('fecha_vencimiento');
            $issuedAt = $this->optionalDate('fecha_emision');
            if ($issuedAt !== null && $issuedAt > $expiresAt) {
                throw new DomainException('La fecha de emisión no puede ser posterior al vencimiento.');
            }

            $db = db_connect();
            $type = $db->table('tipos_vencimiento')
                ->select('id, aplica_a, requiere_documento')
                ->where('empresa_id', $companyId)
                ->where('id', $typeId)
                ->where('activo', 1)
                ->where('deleted_at', null)
                ->get()->getRowArray();

            if ($type === null || ! $this->typeApplies((string) $type['aplica_a'], $subjectType)) {
                throw new DomainException('El tipo de vencimiento no aplica al sujeto seleccionado.');
            }

            $branchId = $this->assertSubjectAndBranch($db, $companyId, $subjectType, $subjectId);
            $documentNumber = $this->nullable('numero_documento', 100);
            if ((int) $type['requiere_documento'] === 1 && $documentNumber === null) {
                throw new DomainException('Este tipo de vencimiento requiere número de documento.');
            }

            $duplicate = $db->table('vencimientos')
                ->where('empresa_id', $companyId)
                ->where('tipo_vencimiento_id', $typeId)
                ->where($subjectType === ExpirationSubjectType::EQUIPMENT ? 'equipo_id' : 'empleado_id', $subjectId)
                ->where('fecha_vencimiento', $expiresAt->format('Y-m-d'))
                ->where('deleted_at', null)
                ->countAllResults() > 0;
            if ($duplicate) {
                throw new DomainException('Ya existe ese vencimiento para la misma fecha.');
            }

            $now = date('Y-m-d H:i:s');
            $db->table('vencimientos')->insert([
                'empresa_id' => $companyId,
                'sucursal_id' => $branchId,
                'tipo_vencimiento_id' => $typeId,
                'sujeto_tipo' => $subjectType->value,
                'equipo_id' => $subjectType === ExpirationSubjectType::EQUIPMENT ? $subjectId : null,
                'empleado_id' => $subjectType === ExpirationSubjectType::EMPLOYEE ? $subjectId : null,
                'fecha_emision' => $issuedAt?->format('Y-m-d'),
                'fecha_vencimiento' => $expiresAt->format('Y-m-d'),
                'numero_documento' => $documentNumber,
                'observaciones' => $this->nullable('observaciones', 2000),
                'origen' => 'MANUAL',
                'activo' => 1,
                'created_by' => $actor->userId(),
                'updated_by' => $actor->userId(),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return redirect()->to($returnTo)->with('success', 'Vencimiento registrado.');
        } catch (Throwable $exception) {
            return $this->failure($exception, $returnTo);
        }
    }

    public function update(int $expirationId): RedirectResponse
    {
        $returnTo = $this->returnTo();
        try {
            $actor = $this->actor();
            $companyId = (int) $actor->companyId();
            $db = db_connect();

            $row = $db->table('vencimientos')
                ->select('*')
                ->where('empresa_id', $companyId)
                ->where('id', $expirationId)
                ->where('deleted_at', null)
                ->get()->getRowArray();
            if ($row === null) {
                throw new DomainException('El vencimiento no existe.');
            }

            $subjectType = ExpirationSubjectType::from((string) $row['sujeto_tipo']);
            $this->assertCanEdit($actor, $subjectType);

            $expiresAt = $this->requiredDate('fecha_vencimiento');
            $issuedAt = $this->optionalDate('fecha_emision');
            if ($issuedAt !== null && $issuedAt > $expiresAt) {
                throw new DomainException('La fecha de emisión no puede ser posterior al vencimiento.');
            }

            $type = $db->table('tipos_vencimiento')
                ->select('requiere_documento')
                ->where('empresa_id', $companyId)
                ->where('id', (int) $row['tipo_vencimiento_id'])
                ->where('deleted_at', null)
                ->get()->getRowArray();
            if ($type === null) {
                throw new DomainException('El tipo de vencimiento ya no existe.');
            }

            $documentNumber = $this->nullable('numero_documento', 100);
            if ((int) $type['requiere_documento'] === 1 && $documentNumber === null) {
                throw new DomainException('Este tipo de vencimiento requiere número de documento.');
            }

            $subjectField = $subjectType === ExpirationSubjectType::EQUIPMENT ? 'equipo_id' : 'empleado_id';
            $subjectId = (int) $row[$subjectField];
            $duplicate = $db->table('vencimientos')
                ->where('empresa_id', $companyId)
                ->where('tipo_vencimiento_id', (int) $row['tipo_vencimiento_id'])
                ->where($subjectField, $subjectId)
                ->where('fecha_vencimiento', $expiresAt->format('Y-m-d'))
                ->where('id !=', $expirationId)
                ->where('deleted_at', null)
                ->countAllResults() > 0;
            if ($duplicate) {
                throw new DomainException('Ya existe ese vencimiento para la misma fecha.');
            }

            $db->table('vencimientos')
                ->where('empresa_id', $companyId)
                ->where('id', $expirationId)
                ->update([
                    'fecha_emision' => $issuedAt?->format('Y-m-d'),
                    'fecha_vencimiento' => $expiresAt->format('Y-m-d'),
                    'numero_documento' => $documentNumber,
                    'observaciones' => $this->nullable('observaciones', 2000),
                    'updated_by' => $actor->userId(),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);

            return redirect()->to($returnTo)->with('success', 'Vencimiento actualizado.');
        } catch (Throwable $exception) {
            return $this->failure($exception, $returnTo);
        }
    }

    public function deactivate(int $expirationId): RedirectResponse
    {
        $returnTo = $this->returnTo();
        try {
            $actor = $this->actor();
            $companyId = (int) $actor->companyId();
            $db = db_connect();

            $row = $db->table('vencimientos')
                ->select('id, sujeto_tipo')
                ->where('empresa_id', $companyId)
                ->where('id', $expirationId)
                ->where('deleted_at', null)
                ->get()->getRowArray();
            if ($row === null) {
                throw new DomainException('El vencimiento no existe.');
            }

            $this->assertCanEdit($actor, ExpirationSubjectType::from((string) $row['sujeto_tipo']));
            $db->table('vencimientos')
                ->where('empresa_id', $companyId)
                ->where('id', $expirationId)
                ->update([
                    'activo' => 0,
                    'updated_by' => $actor->userId(),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);

            return redirect()->to($returnTo)->with('success', 'Vencimiento retirado del circuito activo.');
        } catch (Throwable $exception) {
            return $this->failure($exception, $returnTo);
        }
    }

    private function actor(): ActorContext
    {
        $actor = (new SessionActorContext())->current();
        if ($actor === null || $actor->isSuperAdmin() || $actor->companyId() === null) {
            throw new DomainException('La operación requiere un usuario de empresa.');
        }
        return $actor;
    }

    private function assertCanEditAny(ActorContext $actor): void
    {
        if (! $actor->hasPermission('equipos.editar') && ! $actor->hasPermission('empleados.editar')) {
            throw new DomainException('No tenés permiso para administrar vencimientos.');
        }
    }

    private function assertCanEdit(ActorContext $actor, ExpirationSubjectType $subjectType): void
    {
        $permission = $subjectType === ExpirationSubjectType::EQUIPMENT ? 'equipos.editar' : 'empleados.editar';
        if (! $actor->hasPermission($permission)) {
            throw new DomainException('No tenés permiso para editar vencimientos de este tipo de sujeto.');
        }
    }

    private function subjectType(): ExpirationSubjectType
    {
        return match (mb_strtoupper(trim((string) $this->request->getPost('sujeto_tipo')))) {
            'EQUIPO' => ExpirationSubjectType::EQUIPMENT,
            'EMPLEADO' => ExpirationSubjectType::EMPLOYEE,
            default => throw new DomainException('El sujeto debe ser EQUIPO o EMPLEADO.'),
        };
    }

    private function assertSubjectAndBranch($db, int $companyId, ExpirationSubjectType $subjectType, int $subjectId): ?int
    {
        if ($subjectType === ExpirationSubjectType::EQUIPMENT) {
            $row = $db->table('equipos')->select('sucursal_id')
                ->where('empresa_id', $companyId)
                ->where('id', $subjectId)
                ->where('estado', 'ACTIVO')
                ->where('deleted_at', null)
                ->get()->getRowArray();
            if ($row === null) {
                throw new DomainException('El equipo no existe o está inactivo.');
            }
            return (int) $row['sucursal_id'];
        }

        if ($db->table('empleados')
            ->where('empresa_id', $companyId)
            ->where('id', $subjectId)
            ->where('activo', 1)
            ->where('deleted_at', null)
            ->countAllResults() === 0) {
            throw new DomainException('El empleado no existe o está dado de baja.');
        }

        return null;
    }

    private function typeApplies(string $appliesTo, ExpirationSubjectType $subjectType): bool
    {
        $appliesTo = mb_strtoupper(trim($appliesTo));
        if (in_array($appliesTo, ['AMBOS', 'BOTH'], true)) {
            return true;
        }
        return $subjectType === ExpirationSubjectType::EQUIPMENT
            ? $appliesTo === 'EQUIPO'
            : in_array($appliesTo, ['EMPLEADO', 'EMPLOYEE'], true);
    }

    private function positiveInt(string $field, string $label): int
    {
        $value = (int) $this->request->getPost($field);
        if ($value <= 0) {
            throw new DomainException('Seleccioná un ' . $label . ' válido.');
        }
        return $value;
    }

    private function requiredDate(string $field): DateTimeImmutable
    {
        $value = trim((string) $this->request->getPost($field));
        if ($value === '') {
            throw new DomainException('La fecha de vencimiento es obligatoria.');
        }
        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            throw new DomainException('La fecha indicada no es válida.');
        }
    }

    private function optionalDate(string $field): ?DateTimeImmutable
    {
        $value = trim((string) $this->request->getPost($field));
        if ($value === '') {
            return null;
        }
        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            throw new DomainException('La fecha indicada no es válida.');
        }
    }

    private function nullable(string $field, int $max): ?string
    {
        $value = trim((string) $this->request->getPost($field));
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > $max) {
            throw new DomainException('El valor de ' . $field . ' supera el máximo permitido.');
        }
        return $value;
    }

    private function returnTo(): string
    {
        $value = trim((string) $this->request->getPost('return_to'));
        if ($value === '' || ! str_starts_with($value, '/mantenimiento') || str_starts_with($value, '//')) {
            return '/mantenimiento';
        }
        return $value;
    }

    private function failure(Throwable $exception, string $returnTo): RedirectResponse
    {
        if (! $exception instanceof DomainException) {
            log_message('error', 'Falló la gestión de vencimientos: {message}', ['message' => $exception->getMessage()]);
        }
        return redirect()->to($returnTo)
            ->withInput()
            ->with('error', $exception instanceof DomainException ? $exception->getMessage() : 'No se pudo completar la operación de vencimientos.');
    }
}
