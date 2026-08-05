<?php
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;

class CreateRolPermisosTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'rol_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'permiso_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey(['rol_id', 'permiso_id']);
        $this->forge->addKey('permiso_id');
        $this->forge->addForeignKey('rol_id', 'roles', 'id', '', 'CASCADE');
        $this->forge->addForeignKey('permiso_id', 'permisos', 'id', '', 'CASCADE');
        $this->forge->createTable('rol_permisos');
    }
    public function down() { $this->forge->dropTable('rol_permisos'); }
}