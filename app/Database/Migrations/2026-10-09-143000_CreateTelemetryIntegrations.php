<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Integraciones de telemetría y sus vínculos con los equipos.
 *
 * El modelo es deliberadamente de muchas-a-muchos porque la realidad es así:
 * una empresa puede tener varias cuentas de proveedor, y un mismo equipo
 * puede estar reportando a más de un sistema a la vez. La unicidad de
 * `equipo_id, integracion_id` impide vincular dos veces la misma integración
 * al mismo equipo, pero no impide dos proveedores distintos.
 *
 * El token va cifrado, igual que las credenciales de SMTP, WebPush y WhatsApp
 * en `configuracion_canales_globales`: no pertenece a un `.env`, porque su
 * ciclo de vida es del cliente, no del despliegue.
 */
final class CreateTelemetryIntegrations extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'empresa_id' => ['type' => 'INT', 'unsigned' => true],
            'proveedor' => ['type' => 'VARCHAR', 'constraint' => 32],
            'nombre' => ['type' => 'VARCHAR', 'constraint' => 100],
            'endpoint' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'token_cifrado' => ['type' => 'TEXT', 'null' => true],
            'activo' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'ultimo_ok_en' => ['type' => 'DATETIME', 'null' => true],
            'consecutivos_fallidos' => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'ultimo_error' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'created_by' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_by' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(
            ['empresa_id', 'proveedor', 'nombre'],
            'uq_telemetria_integracion',
        );
        $this->forge->addKey(['empresa_id', 'activo'], false, false, 'idx_telemetria_integracion_empresa');
        $this->forge->createTable('integraciones_telemetria', true);

        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'empresa_id' => ['type' => 'INT', 'unsigned' => true],
            'integracion_id' => ['type' => 'BIGINT', 'unsigned' => true],
            'equipo_id' => ['type' => 'INT', 'unsigned' => true],
            'unidad_externa' => ['type' => 'VARCHAR', 'constraint' => 100],
            'rol' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'SECUNDARIA'],
            'activo' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'created_by' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['integracion_id', 'unidad_externa'], 'uq_equipo_telemetria_unidad');
        $this->forge->addUniqueKey(['equipo_id', 'integracion_id'], 'uq_equipo_telemetria_equipo');
        $this->forge->addKey(['empresa_id', 'equipo_id'], false, false, 'idx_equipo_telemetria_scope');
        $this->forge->createTable('equipo_telemetria', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('equipo_telemetria', true);
        $this->forge->dropTable('integraciones_telemetria', true);
    }
}