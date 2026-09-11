<?php

declare(strict_types=1);

namespace App\Infrastructure\Expirations;

use App\Domain\Expirations\Expiration;
use App\Domain\Expirations\ExpirationSubjectType;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;

final class CodeIgniterExpirationReadModel
{
    public function __construct(private readonly BaseConnection $database)
    {
    }

    /** @return list<array<string,mixed>> */
    public function forEquipment(int $companyId, int $equipmentId): array
    {
        $rows = $this->database->table('vencimientos v')
            ->select('v.*, t.nombre tipo_nombre, t.dias_aviso_previo')
            ->join('tipos_vencimiento t', 't.id = v.tipo_vencimiento_id AND t.empresa_id = v.empresa_id', 'inner')
            ->where('v.empresa_id', $companyId)
            ->where('v.equipo_id', $equipmentId)
            ->where('v.sujeto_tipo', 'EQUIPO')
            ->where('v.activo', 1)
            ->where('v.deleted_at', null)
            ->where('t.deleted_at', null)
            ->orderBy('v.fecha_vencimiento', 'ASC')
            ->get()->getResultArray();

        return array_map(fn (array $row): array => $this->present($row, ExpirationSubjectType::EQUIPMENT, $equipmentId), $rows);
    }

    /** @param list<int> $employeeIds @return array<int,list<array<string,mixed>>> */
    public function forEmployees(int $companyId, array $employeeIds): array
    {
        $employeeIds = array_values(array_unique(array_filter(array_map('intval', $employeeIds), static fn (int $id): bool => $id > 0)));
        if ($employeeIds === []) {
            return [];
        }

        $rows = $this->database->table('vencimientos v')
            ->select('v.*, t.nombre tipo_nombre, t.dias_aviso_previo')
            ->join('tipos_vencimiento t', 't.id = v.tipo_vencimiento_id AND t.empresa_id = v.empresa_id', 'inner')
            ->where('v.empresa_id', $companyId)
            ->where('v.sujeto_tipo', 'EMPLEADO')
            ->whereIn('v.empleado_id', $employeeIds)
            ->where('v.activo', 1)
            ->where('v.deleted_at', null)
            ->where('t.activo', 1)
            ->where('t.deleted_at', null)
            ->orderBy('v.fecha_vencimiento', 'ASC')
            ->get()->getResultArray();

        $grouped = [];
        foreach ($rows as $row) {
            $employeeId = (int) $row['empleado_id'];
            $grouped[$employeeId][] = $this->present($row, ExpirationSubjectType::EMPLOYEE, $employeeId);
        }

        return $grouped;
    }

    /**
     * @param array{subject?:string,status?:string,branchId?:int|null,q?:string} $filters
     * @param list<int>|null $allowedBranchIds
     * @return list<array<string,mixed>>
     */
    public function upcoming(int $companyId, array $filters = [], ?array $allowedBranchIds = null): array
    {
        $builder = $this->database->table('vencimientos v')
            ->select('v.*, t.nombre tipo_nombre, t.dias_aviso_previo, e.codigo equipo_codigo, e.patente equipo_patente, emp.nombre empleado_nombre, emp.apellido empleado_apellido, s.nombre sucursal_nombre')
            ->join('tipos_vencimiento t', 't.id = v.tipo_vencimiento_id AND t.empresa_id = v.empresa_id', 'inner')
            ->join('equipos e', 'e.id = v.equipo_id AND e.empresa_id = v.empresa_id', 'left')
            ->join('empleados emp', 'emp.id = v.empleado_id AND emp.empresa_id = v.empresa_id', 'left')
            ->join('sucursales s', 's.id = v.sucursal_id AND s.empresa_id = v.empresa_id', 'left')
            ->where('v.empresa_id', $companyId)
            ->where('v.activo', 1)
            ->where('v.deleted_at', null)
            ->where('t.activo', 1)
            ->where('t.deleted_at', null);

        $subject = mb_strtoupper(trim((string) ($filters['subject'] ?? 'TODOS')));
        if (in_array($subject, ['EQUIPO', 'EMPLEADO'], true)) {
            $builder->where('v.sujeto_tipo', $subject);
        }

        $branchId = (int) ($filters['branchId'] ?? 0);
        if ($branchId > 0) {
            $builder->where('v.sucursal_id', $branchId);
        } elseif ($allowedBranchIds !== null) {
            $allowedBranchIds = array_values(array_unique(array_filter(array_map('intval', $allowedBranchIds), static fn (int $id): bool => $id > 0)));
            if ($allowedBranchIds !== []) {
                $builder->groupStart()
                    ->whereIn('v.sucursal_id', $allowedBranchIds)
                    ->orWhere('v.sucursal_id', null)
                    ->groupEnd();
            }
        }

        $query = trim((string) ($filters['q'] ?? ''));
        if ($query !== '') {
            $builder->groupStart()
                ->like('t.nombre', $query)
                ->orLike('e.codigo', $query)
                ->orLike('e.patente', $query)
                ->orLike('emp.nombre', $query)
                ->orLike('emp.apellido', $query)
                ->groupEnd();
        }

        $rows = $builder
            ->orderBy('v.fecha_vencimiento', 'ASC')
            ->orderBy('t.nombre', 'ASC')
            ->get()->getResultArray();

        $statusFilter = mb_strtolower(trim((string) ($filters['status'] ?? 'todos')));
        $result = [];
        foreach ($rows as $row) {
            $subjectType = (string) $row['sujeto_tipo'] === 'EQUIPO'
                ? ExpirationSubjectType::EQUIPMENT
                : ExpirationSubjectType::EMPLOYEE;
            $subjectId = $subjectType === ExpirationSubjectType::EQUIPMENT
                ? (int) $row['equipo_id']
                : (int) $row['empleado_id'];

            $expiration = $this->present($row, $subjectType, $subjectId);
            $days = (int) $expiration['daysUntil'];

            $matchesStatus = match ($statusFilter) {
                'vencidos' => $days < 0,
                '7' => $days >= 0 && $days <= 7,
                '15' => $days >= 0 && $days <= 15,
                '30' => $days >= 0 && $days <= 30,
                'vigentes' => $days > 30,
                default => true,
            };
            if (! $matchesStatus) {
                continue;
            }

            $equipment = $subjectType === ExpirationSubjectType::EQUIPMENT;
            $name = $equipment
                ? trim((string) ($row['equipo_codigo'] ?? '') . (! empty($row['equipo_patente']) ? ' · ' . (string) $row['equipo_patente'] : ''))
                : trim((string) ($row['empleado_nombre'] ?? '') . ' ' . (string) ($row['empleado_apellido'] ?? ''));

            $result[] = $expiration + [
                'subjectType' => $subjectType->value,
                'subjectId' => $subjectId,
                'subjectName' => $name === '' ? ($equipment ? 'Equipo #' : 'Empleado #') . $subjectId : $name,
                'branchId' => $row['sucursal_id'] === null ? null : (int) $row['sucursal_id'],
                'branchName' => $row['sucursal_nombre'] ?: null,
                'subjectUrl' => $equipment
                    ? base_url('mantenimiento/equipos/' . $subjectId)
                    : base_url('mantenimiento/empleados?chofer_id=' . $subjectId),
            ];
        }

        return $result;
    }

    /** @return list<array{id:int,name:string}> */
    public function branches(int $companyId, ?array $allowedBranchIds = null): array
    {
        $builder = $this->database->table('sucursales')
            ->select('id, nombre')
            ->where('empresa_id', $companyId)
            ->where('activo', 1)
            ->where('deleted_at', null)
            ->orderBy('nombre', 'ASC');

        if ($allowedBranchIds !== null) {
            $allowedBranchIds = array_values(array_unique(array_filter(array_map('intval', $allowedBranchIds), static fn (int $id): bool => $id > 0)));
            if ($allowedBranchIds === []) {
                return [];
            }
            $builder->whereIn('id', $allowedBranchIds);
        }

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => (string) $row['nombre'],
        ], $builder->get()->getResultArray());
    }

    /** @return list<array{id:int,name:string,appliesTo:string,warningDays:int,requiresDocument:bool,active:bool,updateUrl:string,toggleUrl:string}> */
    public function catalog(int $companyId): array
    {
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => (string) $row['nombre'],
            'appliesTo' => (string) $row['aplica_a'],
            'warningDays' => (int) $row['dias_aviso_previo'],
            'requiresDocument' => (int) $row['requiere_documento'] === 1,
            'active' => (int) $row['activo'] === 1,
            'updateUrl' => base_url('mantenimiento/vencimientos/tipos/' . (int) $row['id']),
            'toggleUrl' => base_url('mantenimiento/vencimientos/tipos/' . (int) $row['id'] . '/estado'),
        ], $this->database->table('tipos_vencimiento')
            ->select('id, nombre, aplica_a, dias_aviso_previo, requiere_documento, activo')
            ->where('empresa_id', $companyId)
            ->where('deleted_at', null)
            ->orderBy('activo', 'DESC')
            ->orderBy('nombre', 'ASC')
            ->get()->getResultArray());
    }

    /** @return list<array{id:int,name:string,appliesTo:string,warningDays:int,requiresDocument:bool}> */
    public function types(int $companyId, ExpirationSubjectType $subjectType): array
    {
        $applies = $subjectType === ExpirationSubjectType::EQUIPMENT
            ? ['EQUIPO', 'AMBOS', 'BOTH']
            : ['EMPLEADO', 'EMPLOYEE', 'AMBOS', 'BOTH'];

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => (string) $row['nombre'],
            'appliesTo' => (string) $row['aplica_a'],
            'warningDays' => (int) $row['dias_aviso_previo'],
            'requiresDocument' => (int) $row['requiere_documento'] === 1,
        ], $this->database->table('tipos_vencimiento')
            ->select('id, nombre, aplica_a, dias_aviso_previo, requiere_documento')
            ->where('empresa_id', $companyId)
            ->whereIn('aplica_a', $applies)
            ->where('activo', 1)
            ->where('deleted_at', null)
            ->orderBy('nombre', 'ASC')
            ->get()->getResultArray());
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function present(array $row, ExpirationSubjectType $subjectType, int $subjectId): array
    {
        $expiration = new Expiration(
            (int) $row['empresa_id'],
            (int) $row['tipo_vencimiento_id'],
            $subjectType,
            $subjectId,
            new DateTimeImmutable((string) $row['fecha_vencimiento']),
            (int) ($row['dias_aviso_previo'] ?? 30),
            empty($row['fecha_emision']) ? null : new DateTimeImmutable((string) $row['fecha_emision']),
            $row['numero_documento'] === null ? null : (string) $row['numero_documento'],
            $row['observaciones'] === null ? null : (string) $row['observaciones'],
            $row['sucursal_id'] === null ? null : (int) $row['sucursal_id'],
            (int) $row['id'],
        );

        $today = new DateTimeImmutable('today');

        return [
            'id' => (int) $row['id'],
            'typeId' => (int) $row['tipo_vencimiento_id'],
            'typeName' => (string) $row['tipo_nombre'],
            'issuedAt' => $row['fecha_emision'] ?: null,
            'expiresAt' => (string) $row['fecha_vencimiento'],
            'documentNumber' => $row['numero_documento'] ?: null,
            'notes' => $row['observaciones'] ?: null,
            'status' => $expiration->statusAt($today)->value,
            'daysUntil' => $expiration->daysUntil($today),
            'origin' => (string) $row['origen'],
            'updateUrl' => base_url('mantenimiento/vencimientos/' . (int) $row['id']),
            'deactivateUrl' => base_url('mantenimiento/vencimientos/' . (int) $row['id'] . '/retirar'),
        ];
    }
}
