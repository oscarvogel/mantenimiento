<?php
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;

class CreateSucursalesTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'empresa_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'codigo' => ['type' => 'VARCHAR', 'constraint' => 20],
            'nombre' => ['type' => 'VARCHAR', 'constraint' => 255],
            'direccion' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'email_alertas' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'estado' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1, 'comment' => '1=activo, 0=inactivo'],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
            'created_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'updated_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'deleted_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['empresa_id', 'codigo']);
        $this->forge->addKey('empresa_id');
        $this->forge->addKey('estado');
        $this->forge->addForeignKey('empresa_id', 'empresas', 'id', '', 'CASCADE');
        $this->forge->createTable('sucursales');
    }
    public function down() { $this->forge->dropTable('sucursales'); }
}