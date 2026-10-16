<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class CreateGeographicCatalogs extends Migration
{
    public function up(): void
    {
        $this->forge->addField($this->catalogFields() + [
            'codigo' => ['type' => 'VARCHAR', 'constraint' => 50],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('codigo', 'uq_paises_codigo');
        $this->forge->createTable('paises');

        $this->forge->addField($this->catalogFields() + [
            'pais_id' => ['type' => 'INT', 'unsigned' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['pais_id', 'nombre'], 'uq_provincias_pais_nombre');
        $this->forge->addForeignKey('pais_id', 'paises', 'id', 'RESTRICT', 'RESTRICT', 'fk_provincias_pais');
        $this->forge->createTable('provincias');

        $this->forge->addField($this->catalogFields() + [
            'provincia_id' => ['type' => 'INT', 'unsigned' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['provincia_id', 'nombre'], 'uq_localidades_provincia_nombre');
        $this->forge->addForeignKey('provincia_id', 'provincias', 'id', 'RESTRICT', 'RESTRICT', 'fk_localidades_provincia');
        $this->forge->createTable('localidades');

        // Codes belong to the source namespace; never reinterpret them as ISO codes.
        $this->forge->addField([
            'id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'pais_origen' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'provincia_origen' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'localidad_origen' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'localidad_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'estado' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'PENDIENTE'],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addField('origen VARCHAR(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL');
        $this->forge->addField('codigo_origen VARCHAR(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL');
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['origen', 'codigo_origen'], 'uq_geo_equivalencias_origen');
        $this->forge->addKey(['estado', 'localidad_id'], false, false, 'idx_geo_equivalencias_estado');
        $this->forge->addForeignKey('localidad_id', 'localidades', 'id', 'RESTRICT', 'RESTRICT', 'fk_geo_equivalencias_localidad');
        $this->forge->createTable('geografia_equivalencias');

        $this->db->table('paises')->insertBatch([
            ['codigo' => 'ARGENTINA', 'nombre' => 'Argentina', 'activo' => 1],
            ['codigo' => 'BRASIL', 'nombre' => 'Brasil', 'activo' => 1],
            ['codigo' => 'CHILE', 'nombre' => 'Chile', 'activo' => 1],
            ['codigo' => 'URUGUAY', 'nombre' => 'Uruguay', 'activo' => 1],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropTable('geografia_equivalencias', true);
        $this->forge->dropTable('localidades', true);
        $this->forge->dropTable('provincias', true);
        $this->forge->dropTable('paises', true);
    }

    private function catalogFields(): array
    {
        return [
            'id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'nombre' => ['type' => 'VARCHAR', 'constraint' => 255],
            'activo' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ];
    }
}
