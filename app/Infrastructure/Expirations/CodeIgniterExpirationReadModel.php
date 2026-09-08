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
            ->where('t.activo', 1)
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
        ];
    }
}
