<?php

declare(strict_types=1);

namespace App\Infrastructure\Expirations;

use App\Application\Expirations\Port\ExpirationDetailReadModel;
use App\Application\Expirations\Port\ExpirationEvidenceReadModel;
use CodeIgniter\Database\BaseConnection;

final class CodeIgniterExpirationEvidenceReadModel implements ExpirationEvidenceReadModel, ExpirationDetailReadModel
{
    public function __construct(private readonly BaseConnection $database)
    {
    }

    public function listForExpiration(int $companyId, int $expirationId): array
    {
        $rows = $this->database->table('vencimiento_evidencias e')
            ->select('e.id, e.empresa_id, e.vencimiento_id, e.nombre_original, e.mime_type, e.tamanio, e.created_at')
            ->where('e.empresa_id', $companyId)
            ->where('e.vencimiento_id', $expirationId)
            ->where('e.deleted_at', null)
            ->orderBy('e.created_at', 'ASC')
            ->get()->getResultArray();

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'empresa_id' => (int) $row['empresa_id'],
            'vencimiento_id' => (int) $row['vencimiento_id'],
            'nombre_original' => (string) $row['nombre_original'],
            'mime_type' => (string) $row['mime_type'],
            'tamanio' => (int) $row['tamanio'],
            'created_at' => substr((string) $row['created_at'], 0, 16),
            'download_url' => base_url('mantenimiento/vencimientos/evidencia/' . (int) $row['id']),
        ], $rows);
    }

    public function historyForExpiration(int $companyId, int $expirationId): array
    {
        $rows = $this->database->table('vencimiento_renovaciones r')
            ->select('r.id, r.empresa_id, r.vencimiento_id, r.fecha_anterior, r.fecha_nueva, r.fecha_renovacion, r.usuario_id, r.observaciones, r.evidencia_id, u.nombre usuario_nombre, u.apellido usuario_apellido, e.nombre_original evidencia_nombre, e.mime_type evidencia_mime, e.tamanio evidencia_tamanio, e.created_at evidencia_created_at')
            ->join('usuarios u', 'u.id = r.usuario_id', 'left')
            ->join('vencimiento_evidencias e', 'e.id = r.evidencia_id AND e.empresa_id = r.empresa_id', 'left')
            ->where('r.empresa_id', $companyId)
            ->where('r.vencimiento_id', $expirationId)
            ->orderBy('r.fecha_renovacion', 'DESC')
            ->orderBy('r.id', 'DESC')
            ->get()->getResultArray();

        return array_map(static function (array $row): array {
            $evidence = null;
            if ($row['evidencia_id'] !== null) {
                $evidence = [
                    'id' => (int) $row['evidencia_id'],
                    'nombre_original' => (string) ($row['evidencia_nombre'] ?? ''),
                    'mime_type' => (string) ($row['evidencia_mime'] ?? ''),
                    'tamanio' => (int) ($row['evidencia_tamanio'] ?? 0),
                    'created_at' => substr((string) ($row['evidencia_created_at'] ?? ''), 0, 16),
                    'download_url' => base_url('mantenimiento/vencimientos/evidencia/' . (int) $row['evidencia_id']),
                ];
            }
            $userId = $row['usuario_id'] === null ? null : (int) $row['usuario_id'];
            $userName = trim(
                (string) ($row['usuario_nombre'] ?? '') . ' ' . (string) ($row['usuario_apellido'] ?? ''),
            );

            return [
                'id' => (int) $row['id'],
                'empresa_id' => (int) $row['empresa_id'],
                'vencimiento_id' => (int) $row['vencimiento_id'],
                'fecha_anterior' => (string) $row['fecha_anterior'],
                'fecha_nueva' => (string) $row['fecha_nueva'],
                'fecha_renovacion' => (string) $row['fecha_renovacion'],
                'usuario_id' => $userId,
                'usuario_nombre' => $userName === '' ? null : $userName,
                'observaciones' => $row['observaciones'] === null ? null : (string) $row['observaciones'],
                'evidencia' => $evidence,
            ];
        }, $rows);
    }

    public function summary(int $companyId, int $expirationId): ?array
    {
        $row = $this->database->table('vencimientos v')
            ->select('v.id, v.empresa_id, v.tipo_vencimiento_id, v.sujeto_tipo, v.equipo_id, v.empleado_id, v.fecha_emision, v.fecha_vencimiento, v.numero_documento, v.observaciones, v.activo, t.nombre tipo_nombre, t.dias_aviso_previo, t.requiere_documento, e.codigo equipo_codigo, e.patente equipo_patente, emp.nombre empleado_nombre, emp.apellido empleado_apellido')
            ->join('tipos_vencimiento t', 't.id = v.tipo_vencimiento_id AND t.empresa_id = v.empresa_id', 'inner')
            ->join('equipos e', 'e.id = v.equipo_id AND e.empresa_id = v.empresa_id', 'left')
            ->join('empleados emp', 'emp.id = v.empleado_id AND emp.empresa_id = v.empresa_id', 'left')
            ->where('v.empresa_id', $companyId)
            ->where('v.id', $expirationId)
            ->where('v.deleted_at', null)
            ->get()->getRowArray();

        if ($row === null) {
            return null;
        }
        if ((int) $row['empresa_id'] !== $companyId) {
            return null;
        }

        $subjectType = (string) $row['sujeto_tipo'];
        $subjectId = $subjectType === 'EQUIPO'
            ? (int) ($row['equipo_id'] ?? 0)
            : (int) ($row['empleado_id'] ?? 0);
        $subjectName = $subjectType === 'EQUIPO'
            ? trim((string) ($row['equipo_codigo'] ?? '') . (! empty($row['equipo_patente']) ? ' - ' . (string) $row['equipo_patente'] : ''))
            : trim((string) ($row['empleado_nombre'] ?? '') . ' ' . (string) ($row['empleado_apellido'] ?? ''));

        return [
            'id' => (int) $row['id'],
            'empresa_id' => (int) $row['empresa_id'],
            'tipo_vencimiento_id' => (int) $row['tipo_vencimiento_id'],
            'tipo_nombre' => (string) ($row['tipo_nombre'] ?? ''),
            'sujeto_tipo' => $subjectType,
            'subject_id' => $subjectId,
            'subject_name' => $subjectName,
            'fecha_vencimiento' => (string) $row['fecha_vencimiento'],
            'fecha_emision' => empty($row['fecha_emision']) ? null : (string) $row['fecha_emision'],
            'numero_documento' => $row['numero_documento'] === null ? null : (string) $row['numero_documento'],
            'observaciones' => $row['observaciones'] === null ? null : (string) $row['observaciones'],
            'requiere_documento' => (int) ($row['requiere_documento'] ?? 0) === 1,
            'dias_aviso_previo' => (int) ($row['dias_aviso_previo'] ?? 30),
            'activo' => (int) ($row['activo'] ?? 1) === 1,
        ];
    }
}
