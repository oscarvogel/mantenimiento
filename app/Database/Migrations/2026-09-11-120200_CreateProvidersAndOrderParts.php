<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class CreateProvidersAndOrderParts extends Migration
{
    private const PERMISSIONS = [
        'proveedores.ver' => 'Consultar proveedores y talleres',
        'proveedores.editar' => 'Crear, editar e inactivar proveedores y talleres',
    ];

    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'empresa_id' => ['type' => 'INT', 'unsigned' => true],
            'razon_social' => ['type' => 'VARCHAR', 'constraint' => 255],
            'cuit' => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'es_taller' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'es_proveedor' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'email' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'telefono' => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => true],
            'direccion' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'especialidad' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'activo' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'observaciones' => ['type' => 'TEXT', 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        // Permite que las piezas referencien al proveedor dentro del mismo
        // tenant mediante una FK compuesta.
        $this->forge->addUniqueKey(['empresa_id', 'id'], 'uq_proveedores_empresa_id');
        $this->forge->addKey(['empresa_id', 'activo', 'razon_social'], false, false, 'idx_proveedores_scope_activo');
        $this->forge->addUniqueKey(['empresa_id', 'cuit'], 'uq_proveedores_empresa_cuit');
        $this->forge->addForeignKey('empresa_id', 'empresas', 'id', 'RESTRICT', 'RESTRICT', 'fk_proveedores_empresa');
        $this->forge->createTable('proveedores', true);

        $this->forge->addField([
            'id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'empresa_id' => ['type' => 'INT', 'unsigned' => true],
            'orden_id' => ['type' => 'INT', 'unsigned' => true],
            'codigo' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'descripcion' => ['type' => 'VARCHAR', 'constraint' => 255],
            'marca' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'numero_serie_lote' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'cantidad' => ['type' => 'DECIMAL', 'constraint' => '12,3', 'default' => 1],
            'precio_unitario' => ['type' => 'DECIMAL', 'constraint' => '14,2', 'default' => 0],
            'proveedor_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'comprobante' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'fecha_colocacion' => ['type' => 'DATE', 'null' => true],
            'garantia_fecha' => ['type' => 'DATE', 'null' => true],
            'garantia_km' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'garantia_horas' => ['type' => 'DECIMAL', 'constraint' => '12,1', 'unsigned' => true, 'null' => true],
            'repuesto_retirado' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'observaciones' => ['type' => 'TEXT', 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['empresa_id', 'orden_id'], false, false, 'idx_orden_repuestos_scope_orden');
        $this->forge->addKey(['empresa_id', 'garantia_fecha'], false, false, 'idx_orden_repuestos_garantia');
        $this->forge->addForeignKey(['empresa_id', 'orden_id'], 'ordenes_trabajo', ['empresa_id', 'id'], 'RESTRICT', 'CASCADE', 'fk_orden_repuestos_orden');
        $this->forge->addForeignKey(['empresa_id', 'proveedor_id'], 'proveedores', ['empresa_id', 'id'], 'RESTRICT', 'RESTRICT', 'fk_orden_repuestos_proveedor_tenant');
        $this->forge->createTable('orden_repuestos', true);

        foreach (self::PERMISSIONS as $key => $description) {
            if ($this->db->table('permisos')->where('clave', $key)->countAllResults() === 0) {
                $this->db->table('permisos')->insert(['clave' => $key, 'descripcion' => $description, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
            }
        }
        $this->grant('Administrador', array_keys(self::PERMISSIONS));
        $this->grant('Responsable de mantenimiento', array_keys(self::PERMISSIONS));
    }

    public function down(): void
    {
        $this->forge->dropTable('orden_repuestos', true);
        $this->forge->dropTable('proveedores', true);
        $keys = $this->db->table('permisos')->select('id')->whereIn('clave', array_keys(self::PERMISSIONS))->get()->getResultArray();
        $ids = array_column($keys, 'id');
        if ($ids !== []) {
            $this->db->table('rol_permisos')->whereIn('permiso_id', $ids)->delete();
            $this->db->table('permisos')->whereIn('id', $ids)->delete();
        }
    }

    private function grant(string $roleName, array $keys): void
    {
        $role = $this->db->table('roles')->select('id')->where('nombre', $roleName)->get()->getRowArray();
        if ($role === null) return;
        foreach ($keys as $key) {
            $permission = $this->db->table('permisos')->select('id')->where('clave', $key)->get()->getRowArray();
            if ($permission === null) continue;
            $relation = ['rol_id' => (int) $role['id'], 'permiso_id' => (int) $permission['id']];
            if ($this->db->table('rol_permisos')->where($relation)->countAllResults() === 0) $this->db->table('rol_permisos')->insert($relation + ['created_at' => date('Y-m-d H:i:s')]);
        }
    }
}
