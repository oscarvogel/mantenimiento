<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Última instantánea conocida de cada equipo, por fuente de telemetría.
 *
 * Es una tabla de **estado actual**, no de histórico: una fila por
 * combinación de integración y unidad externa, que se sobrescribe en cada
 * corrida. El histórico de verdad no vive acá.
 *
 * Existe para dos razones:
 *
 * 1. La ficha y el mapa leen de nuestra base y no del proveedor. Una pantalla
 *    no puede tardar 3 segundos por equipo ni romperse cuando el proveedor
 *    está caído.
 * 2. Guarda la **observada_en**, que es la del proveedor. Sin ella, un punto
 *    en el mapa no dice si el camión está ahí o estuvo ahí hace una semana.
 */
final class CreateTelemetryLastSnapshot extends Migration
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
            'observada_en' => ['type' => 'DATETIME'],
            'registrada_en' => ['type' => 'DATETIME', 'null' => true],
            'latitud' => ['type' => 'DECIMAL', 'constraint' => '10,7', 'null' => true],
            'longitud' => ['type' => 'DECIMAL', 'constraint' => '10,7', 'null' => true],
            'velocidad_kmh' => ['type' => 'DECIMAL', 'constraint' => '7,2', 'null' => true],
            'rumbo' => ['type' => 'SMALLINT', 'unsigned' => true, 'null' => true],
            'altitud_m' => ['type' => 'DECIMAL', 'constraint' => '8,2', 'null' => true],
            'satelites' => ['type' => 'SMALLINT', 'unsigned' => true, 'null' => true],
            'kilometraje' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'horas_decimales' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'motor_encendido' => ['type' => 'TINYINT', 'constraint' => 1, 'null' => true],
            'ralenti_activo' => ['type' => 'TINYINT', 'constraint' => 1, 'null' => true],
            'voltaje' => ['type' => 'DECIMAL', 'constraint' => '6,2', 'null' => true],
            'combustible_litros' => ['type' => 'DECIMAL', 'constraint' => '8,2', 'null' => true],
            'sensores_adicionales' => ['type' => 'TEXT', 'null' => true],
            'ausente' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['integracion_id', 'unidad_externa'], 'uq_telemetria_snap_fuente');
        $this->forge->addKey(['empresa_id', 'equipo_id'], false, false, 'idx_telemetria_snap_equipo');
        $this->forge->createTable('telematia_ultima_lectura', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('telematia_ultima_lectura', true);
    }
}