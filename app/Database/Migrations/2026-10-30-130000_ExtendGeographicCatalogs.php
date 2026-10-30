<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

final class ExtendGeographicCatalogs extends Migration
{
    public function up(): void
    {
        $this->db->resetDataCache();
        $this->addColumnsIfMissing('provincias', [
            'identificador_fuente' => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true],
            'fuente' => ['type' => 'VARCHAR', 'constraint' => 80, 'null' => true],
            'codigo_fuente' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
        ]);
        $this->addColumnsIfMissing('localidades', [
            'identificador_fuente' => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true],
            'fuente' => ['type' => 'VARCHAR', 'constraint' => 80, 'null' => true],
            'codigo_fuente' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'version_fuente' => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'contexto_administrativo' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'categoria_fuente' => ['type' => 'TEXT', 'null' => true],
            'latitud' => ['type' => 'DECIMAL', 'constraint' => '10,7', 'null' => true],
            'longitud' => ['type' => 'DECIMAL', 'constraint' => '10,7', 'null' => true],
        ]);

        $this->forge->addUniqueKey('identificador_fuente', 'uq_provincias_identificador_fuente');
        $this->forge->processIndexes('provincias');
        $this->forge->addUniqueKey('identificador_fuente', 'uq_localidades_identificador_fuente');
        $this->forge->processIndexes('localidades');

        if (! $this->db->tableExists('geografia_importaciones')) {
            $this->forge->addField([
                'id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'pais_id' => ['type' => 'INT', 'unsigned' => true],
                'fuente' => ['type' => 'VARCHAR', 'constraint' => 80],
                'version_fuente' => ['type' => 'VARCHAR', 'constraint' => 40],
                'sha256_archivo' => ['type' => 'CHAR', 'constraint' => 64],
                'filas_recibidas' => ['type' => 'INT', 'unsigned' => true],
                'localidades_nuevas' => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
                'localidades_actualizadas' => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
                'localidades_sin_cambios' => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
                'usuario_id' => ['type' => 'INT', 'unsigned' => true],
                'created_at' => ['type' => 'DATETIME'],
            ]);
            $this->forge->addPrimaryKey('id');
            $this->forge->addKey(['pais_id', 'created_at'], false, false, 'idx_geografia_importaciones_pais_fecha');
            $this->forge->addKey('usuario_id', false, false, 'idx_geografia_importaciones_usuario');
            $this->forge->addForeignKey('pais_id', 'paises', 'id', 'RESTRICT', 'RESTRICT');
            $this->forge->addForeignKey('usuario_id', 'usuarios', 'id', 'RESTRICT', 'RESTRICT');
            $this->forge->createTable('geografia_importaciones');
        }
    }

    public function down(): void
    {
        $this->db->resetDataCache();
        if ($this->db->tableExists('geografia_importaciones') && $this->db->table('geografia_importaciones')->countAllResults() > 0) {
            throw new RuntimeException('down() no puede eliminar el historial de importaciones geográficas.');
        }

        foreach (['provincias', 'localidades'] as $table) {
            if ($this->db->fieldExists('identificador_fuente', $table)) {
                $tableName = $this->db->protectIdentifiers($table, true);
                $count = (int) $this->db->query(
                    'SELECT COUNT(*) AS total FROM ' . $tableName . ' WHERE identificador_fuente IS NOT NULL',
                )->getRowArray()['total'];
                if ($count > 0) {
                    throw new RuntimeException('down() no puede eliminar localidades o provincias importadas.');
                }
            }
        }

        if ($this->db->tableExists('geografia_importaciones')) {
            $this->forge->dropTable('geografia_importaciones', true);
        }
        $this->dropColumnsIfPresent('localidades', [
            'longitud', 'latitud', 'categoria_fuente', 'contexto_administrativo', 'version_fuente',
            'codigo_fuente', 'fuente', 'identificador_fuente',
        ]);
        $this->dropColumnsIfPresent('provincias', ['codigo_fuente', 'fuente', 'identificador_fuente']);
    }

    private function addColumnsIfMissing(string $table, array $fields): void
    {
        foreach ($fields as $field => $definition) {
            if ($this->db->fieldExists($field, $table)) {
                continue;
            }
            $this->forge->addColumn($table, [$field => $definition]);
            $this->db->resetDataCache();
        }
    }

    private function dropColumnsIfPresent(string $table, array $fields): void
    {
        foreach ($fields as $field) {
            if ($this->db->fieldExists($field, $table)) {
                $this->forge->dropColumn($table, $field);
                $this->db->resetDataCache();
            }
        }
    }
}
