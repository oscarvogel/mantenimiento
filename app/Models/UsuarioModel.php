<?php

namespace App\Models;

use CodeIgniter\Model;

class UsuarioModel extends Model
{
    protected $table         = 'usuarios';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useSoftDeletes = true;
    protected $useTimestamps = true;
    protected $allowedFields = [
        'empresa_id', 'nombre', 'email', 'password_hash', 'es_superadmin', 'activo', 'ultimo_acceso',
    ];

    public function findByEmail(string $email): ?array
    {
        return $this->where('email', $email)->where('activo', 1)->first();
    }

    public function findActiveById(int $usuarioId): ?array
    {
        return $this->where($this->primaryKey, $usuarioId)->where('activo', 1)->first();
    }

    public function companyIsActive(int $companyId): bool
    {
        return $this->db->table('empresas')
            ->where('id', $companyId)
            ->where('estado', 1)
            ->where('deleted_at', null)
            ->countAllResults() === 1;
    }

    public function company(int $companyId): ?array
    {
        return $this->db->table('empresas')
            ->select('id, razon_social, nombre_fantasia')
            ->where('id', $companyId)
            ->where('deleted_at', null)
            ->get()->getRowArray();
    }

    public function roles(int $usuarioId): array
    {
        return $this->db->table('usuario_roles ur')
            ->select('r.id, r.nombre, r.descripcion')
            ->join('roles r', 'r.id = ur.rol_id', 'inner')
            ->where('ur.usuario_id', $usuarioId)
            ->get()->getResultArray();
    }

    public function sucursales(int $usuarioId, bool $allCompanyBranches = false): array
    {
        $usuario = $this->findActiveById($usuarioId);

        if ($usuario === null || $usuario['empresa_id'] === null || (bool) $usuario['es_superadmin']) {
            return [];
        }

        if ($allCompanyBranches) {
            return $this->db->table('sucursales s')
                ->select('s.id, s.empresa_id, s.codigo, s.nombre')
                ->where('s.empresa_id', $usuario['empresa_id'])
                ->where('s.estado', 1)
                ->where('s.deleted_at', null)
                ->orderBy('s.nombre')
                ->get()->getResultArray();
        }

        return $this->db->table('usuario_sucursales us')
            ->select('s.id, s.empresa_id, s.codigo, s.nombre')
            ->join('sucursales s', 's.id = us.sucursal_id', 'inner')
            ->where('us.usuario_id', $usuarioId)
            ->where('s.empresa_id', $usuario['empresa_id'])
            ->where('s.estado', 1)
            ->where('s.deleted_at', null)
            ->orderBy('s.nombre')
            ->get()->getResultArray();
    }

    public function permisos(int $usuarioId): array
    {
        $rows = $this->db->table('usuario_roles ur')
            ->distinct()
            ->select('p.clave')
            ->join('rol_permisos rp', 'rp.rol_id = ur.rol_id', 'inner')
            ->join('permisos p', 'p.id = rp.permiso_id', 'inner')
            ->where('ur.usuario_id', $usuarioId)
            ->get()->getResultArray();
        return array_column($rows, 'clave');
    }

    public function hasPermission(int $usuarioId, string $clave): bool
    {
        return in_array($clave, $this->permisos($usuarioId), true);
    }

    public function touchLastAccess(int $usuarioId): void
    {
        $this->update($usuarioId, ['ultimo_acceso' => date('Y-m-d H:i:s')]);
    }
}
