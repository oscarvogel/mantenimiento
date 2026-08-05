<?php
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;

class CreateUsuarioRolesTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'usuario_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'rol_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey(['usuario_id', 'rol_id']);
        $this->forge->addKey('rol_id');
        $this->forge->addForeignKey('usuario_id', 'usuarios', 'id', '', 'CASCADE');
        $this->forge->addForeignKey('rol_id', 'roles', 'id', '', 'CASCADE');
        $this->forge->createTable('usuario_roles');
    }
    public function down() { $this->forge->dropTable('usuario_roles'); }
}