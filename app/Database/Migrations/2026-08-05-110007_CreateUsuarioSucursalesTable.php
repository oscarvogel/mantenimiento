<?php
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;

class CreateUsuarioSucursalesTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'usuario_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'sucursal_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey(['usuario_id', 'sucursal_id']);
        $this->forge->addKey('sucursal_id');
        $this->forge->addForeignKey('usuario_id', 'usuarios', 'id', '', 'CASCADE');
        $this->forge->addForeignKey('sucursal_id', 'sucursales', 'id', '', 'CASCADE');
        $this->forge->createTable('usuario_sucursales');
    }
    public function down() { $this->forge->dropTable('usuario_sucursales'); }
}