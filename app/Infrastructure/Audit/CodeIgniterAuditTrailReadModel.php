<?php

declare(strict_types=1);

namespace App\Infrastructure\Audit;

use App\Application\Audit\Port\AuditTrailReadModel;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use JsonException;

final class CodeIgniterAuditTrailReadModel implements AuditTrailReadModel
{
    public function __construct(private readonly BaseConnection $database)
    {
    }

    public function paginate(array $filters, int $page, int $perPage): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));

        $total = $this->builder($filters)->countAllResults();
        $pages = $total === 0 ? 0 : (int) ceil($total / $perPage);

        $rows = $this->builder($filters)
            ->select(
                'a.id, a.empresa_id, a.sucursal_id, a.actor_usuario_id, a.actor_empresa_id, '
                . 'a.alcance, a.accion, a.entidad, a.entidad_id, a.valores_anteriores, '
                . 'a.valores_nuevos, a.motivo, a.ip, a.user_agent, a.request_id, a.fecha, '
                . 'e.razon_social empresa_nombre, e.nombre_fantasia empresa_fantasia, '
                . 's.nombre sucursal_nombre, u.nombre actor_nombre, u.email actor_email, '
                . 'ae.razon_social actor_empresa_nombre, ae.nombre_fantasia actor_empresa_fantasia',
                false,
            )
            ->orderBy('a.fecha', 'DESC')
            ->orderBy('a.id', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()
            ->getResultArray();

        return [
            'items'    => array_map(fn (array $row): array => $this->mapRow($row), $rows),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'pages'    => $pages,
        ];
    }

    /** @param array<string, mixed> $filters */
    private function builder(array $filters): BaseBuilder
    {
        $builder = $this->database->table('auditoria a')
            ->join('empresas e', 'e.id = a.empresa_id', 'left')
            ->join('sucursales s', 's.id = a.sucursal_id AND s.empresa_id = a.empresa_id', 'left')
            ->join('usuarios u', 'u.id = a.actor_usuario_id', 'left')
            ->join('empresas ae', 'ae.id = a.actor_empresa_id', 'left');

        if ($filters['companyId'] !== null) {
            $builder->where('a.empresa_id', $filters['companyId']);
        }
        if ($filters['branchId'] !== null) {
            $builder->where('a.sucursal_id', $filters['branchId']);
        }
        if ($filters['actorUserId'] !== null) {
            $builder->where('a.actor_usuario_id', $filters['actorUserId']);
        }
        if ($filters['action'] !== '') {
            $builder->where('a.accion', $filters['action']);
        }
        if ($filters['entity'] !== '') {
            $builder->where('a.entidad', $filters['entity']);
        }
        if ($filters['entityId'] !== '') {
            $builder->where('a.entidad_id', $filters['entityId']);
        }
        if ($filters['dateFrom'] !== null) {
            $builder->where('a.fecha >=', $filters['dateFrom'] . ' 00:00:00');
        }
        if ($filters['dateTo'] !== null) {
            $builder->where('a.fecha <=', $filters['dateTo'] . ' 23:59:59');
        }
        if ($filters['search'] !== '') {
            $builder->groupStart()
                ->like('a.entidad', $filters['search'])
                ->orLike('a.accion', $filters['search'])
                ->orLike('a.motivo', $filters['search'])
                ->orLike('a.entidad_id', $filters['search'])
                ->orLike('u.nombre', $filters['search'])
                ->orLike('u.email', $filters['search'])
                ->orLike('e.razon_social', $filters['search'])
                ->orLike('s.nombre', $filters['search'])
                ->groupEnd();
        }

        return $builder;
    }

    /** @param array<string, mixed> $row */
    private function mapRow(array $row): array
    {
        $companyId = $row['empresa_id'] === null ? null : (int) $row['empresa_id'];
        $branchId = $row['sucursal_id'] === null ? null : (int) $row['sucursal_id'];
        $actorId = $row['actor_usuario_id'] === null ? null : (int) $row['actor_usuario_id'];
        $actorCompanyId = $row['actor_empresa_id'] === null ? null : (int) $row['actor_empresa_id'];

        return [
            'id'           => (int) $row['id'],
            'fecha'        => (string) $row['fecha'],
            'scope'        => (string) $row['alcance'],
            'action'       => (string) $row['accion'],
            'entity'       => (string) $row['entidad'],
            'entityId'     => $row['entidad_id'] === null ? null : (string) $row['entidad_id'],
            'reason'       => $row['motivo'] === null ? null : (string) $row['motivo'],
            'ip'           => $row['ip'] === null ? null : (string) $row['ip'],
            'userAgent'    => $row['user_agent'] === null ? null : (string) $row['user_agent'],
            'requestId'    => $row['request_id'] === null ? null : (string) $row['request_id'],
            'before'       => $this->decode($row['valores_anteriores']),
            'after'        => $this->decode($row['valores_nuevos']),
            'company'      => $companyId === null ? null : [
                'id'   => $companyId,
                'name' => $this->displayName($row['empresa_fantasia'] ?? null, $row['empresa_nombre'] ?? null, $companyId),
            ],
            'branch'       => $branchId === null ? null : [
                'id'   => $branchId,
                'name' => trim((string) ($row['sucursal_nombre'] ?? '')) ?: 'Sucursal #' . $branchId,
            ],
            'actor'        => $actorId === null ? null : [
                'id'    => $actorId,
                'name'  => trim((string) ($row['actor_nombre'] ?? '')) ?: 'Usuario #' . $actorId,
                'email' => $row['actor_email'] === null ? null : (string) $row['actor_email'],
            ],
            'actorCompany' => $actorCompanyId === null ? null : [
                'id'   => $actorCompanyId,
                'name' => $this->displayName($row['actor_empresa_fantasia'] ?? null, $row['actor_empresa_nombre'] ?? null, $actorCompanyId),
            ],
        ];
    }

    private function displayName(mixed $fantasyName, mixed $legalName, int $id): string
    {
        $fantasyName = trim((string) ($fantasyName ?? ''));
        if ($fantasyName !== '') {
            return $fantasyName;
        }

        $legalName = trim((string) ($legalName ?? ''));

        return $legalName !== '' ? $legalName : 'Empresa #' . $id;
    }

    /** @return array<string, mixed>|null */
    private function decode(mixed $value): ?array
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        try {
            $decoded = json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : null;
        } catch (JsonException) {
            return null;
        }
    }
}
