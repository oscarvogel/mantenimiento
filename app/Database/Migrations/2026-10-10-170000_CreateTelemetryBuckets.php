<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Histórico compacto: extremos observados de cada bucket UTC de telemetría. */
final class CreateTelemetryBuckets extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'empresa_id' => ['type' => 'INT', 'unsigned' => true],
            'integracion_id' => ['type' => 'BIGINT', 'unsigned' => true],
            'equipo_id' => ['type' => 'INT', 'unsigned' => true],
            'unidad_externa' => ['type' => 'VARCHAR', 'constraint' => 100],
            'proveedor' => ['type' => 'VARCHAR', 'constraint' => 32],
            'bucket_inicio_utc' => ['type' => 'DATETIME'],
            'bucket_fin_utc' => ['type' => 'DATETIME'],
            'primera_observada_en' => ['type' => 'DATETIME'],
            'ultima_observada_en' => ['type' => 'DATETIME'],
            'combustible_inicio_litros' => ['type' => 'DECIMAL', 'constraint' => '10,3', 'null' => true],
            'combustible_fin_litros' => ['type' => 'DECIMAL', 'constraint' => '10,3', 'null' => true],
            'kilometraje_inicio' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'kilometraje_fin' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'horas_decimales_inicio' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'horas_decimales_fin' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'motor_inicio' => ['type' => 'TINYINT', 'constraint' => 1, 'null' => true],
            'motor_fin' => ['type' => 'TINYINT', 'constraint' => 1, 'null' => true],
            'ralenti_inicio' => ['type' => 'TINYINT', 'constraint' => 1, 'null' => true],
            'ralenti_fin' => ['type' => 'TINYINT', 'constraint' => 1, 'null' => true],
            'segundos_ralenti' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'latitud_inicio' => ['type' => 'DECIMAL', 'constraint' => '10,7', 'null' => true],
            'longitud_inicio' => ['type' => 'DECIMAL', 'constraint' => '10,7', 'null' => true],
            'velocidad_inicio_kmh' => ['type' => 'DECIMAL', 'constraint' => '7,2', 'null' => true],
            'rumbo_inicio' => ['type' => 'SMALLINT', 'unsigned' => true, 'null' => true],
            'altitud_inicio_m' => ['type' => 'DECIMAL', 'constraint' => '8,2', 'null' => true],
            'satelites_inicio' => ['type' => 'SMALLINT', 'unsigned' => true, 'null' => true],
            'latitud_fin' => ['type' => 'DECIMAL', 'constraint' => '10,7', 'null' => true],
            'longitud_fin' => ['type' => 'DECIMAL', 'constraint' => '10,7', 'null' => true],
            'velocidad_fin_kmh' => ['type' => 'DECIMAL', 'constraint' => '7,2', 'null' => true],
            'rumbo_fin' => ['type' => 'SMALLINT', 'unsigned' => true, 'null' => true],
            'altitud_fin_m' => ['type' => 'DECIMAL', 'constraint' => '8,2', 'null' => true],
            'satelites_fin' => ['type' => 'SMALLINT', 'unsigned' => true, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(
            ['integracion_id', 'unidad_externa', 'bucket_inicio_utc'],
            'uq_telemetria_buckets_unidad_inicio',
        );
        $this->forge->addKey(
            ['empresa_id', 'equipo_id', 'bucket_inicio_utc'],
            false,
            false,
            'idx_telemetria_buckets_equipo',
        );
        $this->forge->addKey(
            ['empresa_id', 'bucket_inicio_utc'],
            false,
            false,
            'idx_telemetria_buckets_retencion',
        );
        $this->forge->createTable('telematia_buckets', true, ['ENGINE' => 'InnoDB', 'CHARSET' => 'utf8mb4']);

        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'empresa_id' => ['type' => 'INT', 'unsigned' => true],
            'integracion_id' => ['type' => 'BIGINT', 'unsigned' => true],
            'equipo_id' => ['type' => 'INT', 'unsigned' => true],
            'unidad_externa' => ['type' => 'VARCHAR', 'constraint' => 100],
            'proveedor' => ['type' => 'VARCHAR', 'constraint' => 32],
            'cursor_hasta_utc' => ['type' => 'DATETIME'],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(
            ['integracion_id', 'unidad_externa'],
            'uq_telemetria_cursor_unidad',
        );
        $this->forge->addKey(
            ['empresa_id', 'equipo_id', 'cursor_hasta_utc'],
            false,
            false,
            'idx_telemetria_cursor_empresa',
        );
        $this->forge->createTable('telematia_cursor', true, ['ENGINE' => 'InnoDB', 'CHARSET' => 'utf8mb4']);
    }

    public function down(): void
    {
        $this->forge->dropTable('telematia_cursor', true);
        $this->forge->dropTable('telematia_buckets', true);
    }
}
