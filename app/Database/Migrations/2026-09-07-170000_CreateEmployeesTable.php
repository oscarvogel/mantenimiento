<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class CreateEmployeesTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'empresa_id' => ['type' => 'INT', 'unsigned' => true],
            'nombre' => ['type' => 'VARCHAR', 'constraint' => 100],
            'apellido' => ['type' => 'VARCHAR', 'constraint' => 100, 'default' => ''],
            'documento' => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true],
            'cuil' => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true],
            'legajo' => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => true],
            'telefono' => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => true],
            'email' => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => true],
            'fecha_ingreso' => ['type' => 'DATE', 'null' => true],
            'observaciones' => ['type' => 'TEXT', 'null' => true],
            'activo' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'fecha_baja' => ['type' => 'DATE', 'null' => true],
            'motivo_baja' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'importado_incompleto' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
            'created_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'updated_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'deleted_at' => ['type' => 'DATETIME', 'null' => true],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey(['empresa_id', 'activo']);
        $this->forge->addKey(['empresa_id', 'apellido', 'nombre']);
        $this->forge->addUniqueKey(['empresa_id', 'documento'], 'uq_empleados_empresa_documento');
        $this->forge->addUniqueKey(['empresa_id', 'legajo'], 'uq_empleados_empresa_legajo');
        $this->forge->addForeignKey('empresa_id', 'empresas', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey('created_by', 'usuarios', 'id', 'SET NULL', 'RESTRICT');
        $this->forge->addForeignKey('updated_by', 'usuarios', 'id', 'SET NULL', 'RESTRICT');
        $this->forge->createTable('empleados', true);

        $this->ensurePermission('empleados.ver', 'Consultar empleados y su afectación a móviles');
        $this->ensurePermission('empleados.editar', 'Crear, editar, dar de baja y asignar empleados');

        foreach (['Administrador de empresa', 'Responsable de mantenimiento'] as $roleName) {
            $this->grantPermission($roleName, 'empleados.ver');
            $this->grantPermission($roleName, 'empleados.editar');
        }
    }

    public function down(): void
    {
        $this->forge->dropTable('empleados', true);
    }

    private function ensurePermission(string $key, string $description): void
    {
        if ($this->db->table('permisos')->where('clave', $key)->countAllResults() > 0) {
            return;
        }

        $this->db->table('permisos')->insert([
            'clave' => $key,
            'descripcion' => $description,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function grantPermission(string $roleName, string $permissionKey): void
    {
        $role = $this->db->table('roles')->select('id')->where('nombre', $roleName)->get()->getRowArray();
        $permission = $this->db->table('permisos')->select('id')->where('clave', $permissionKey)->get()->getRowArray();
        if ($role === null || $permission === null) {
            return;
        }

        $relation = ['rol_id' => (int) $role['id'], 'permiso_id' => (int) $permission['id']];
        if ($this->db->table('rol_permisos')->where($relation)->countAllResults() === 0) {
            $this->db->table('rol_permisos')->insert($relation + ['created_at' => date('Y-m-d H:i:s')]);
        }
    }
}
