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
        'empresa_id', 'nombre', 'email', 'password_hash', 'activo', 'ultimo_acceso',
    ];

    public function findByEmail(string $email): ?array
    {
        return $this->where('email', $email)->where('activo', 1)->first();
    }

    public function roles(int $usuarioId): array
    {
        return $this->db->table('usuario_roles ur')
            ->select('r.id, r.nombre, r.descripcion')
            ->join('roles r', 'r.id = ur.rol_id', 'inner')
            ->where('ur.usuario_id', $usuarioId)
            ->get()->getResultArray();
    }

    public function sucursales(int $usuarioId): array
    {
        return $this->db->table('usuario_sucursales us')
            ->select('s.id, s.empresa_id, s.codigo, s.nombre')
            ->join('sucursales s', 's.id = us.sucursal_id', 'inner')
            ->where('us.usuario_id', $usuarioId)
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