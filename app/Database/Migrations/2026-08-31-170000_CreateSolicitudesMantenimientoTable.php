<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class CreateSolicitudesMantenimientoTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'empresa_id' => ['type' => 'INT', 'unsigned' => true],
            'sucursal_id' => ['type' => 'INT', 'unsigned' => true],
            'equipo_id' => ['type' => 'INT', 'unsigned' => true],
            'reportado_por' => ['type' => 'INT', 'unsigned' => true],
            'fecha_reporte' => ['type' => 'DATETIME'],
            'descripcion' => ['type' => 'TEXT'],
            'estado' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'PENDIENTE'],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['empresa_id', 'estado']);
        $this->forge->addKey(['empresa_id', 'equipo_id', 'fecha_reporte']);
        $this->forge->addForeignKey('empresa_id', 'empresas', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey('sucursal_id', 'sucursales', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey('equipo_id', 'equipos', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey('reportado_por', 'usuarios', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->createTable('solicitudes_mantenimiento', true);

        // Los choferes se modelan con el rol existente "Tecnico u operador":
        // puede cargar lecturas y ahora también reportar una falla breve.
        $this->grantPermission('Tecnico u operador', 'solicitudes.crear');
        $this->grantPermission('Responsable de mantenimiento', 'solicitudes.crear');
        $this->grantPermission('Responsable de mantenimiento', 'solicitudes.revisar');
    }

    public function down(): void
    {
        $this->forge->dropTable('solicitudes_mantenimiento', true);
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
