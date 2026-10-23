<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class AddDriverSettlement extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('viaje_operativo_gastos', [
            'pagado_por' => [
                'type' => 'VARCHAR',
                'constraint' => 20,
                'default' => 'EMPRESA',
                'after' => 'medio_pago_id',
            ],
        ]);
        $this->db->table('viaje_operativo_gastos')
            ->where('pagado_de_caja', 1)
            ->update(['pagado_por' => 'CAJA']);

        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'viaje_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'empresa_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'sucursal_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'empleado_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'fecha' => ['type' => 'DATE'],
            'concepto' => ['type' => 'VARCHAR', 'constraint' => 120],
            'importe' => ['type' => 'DECIMAL', 'constraint' => '18,4'],
            'moneda' => ['type' => 'CHAR', 'constraint' => 3, 'default' => 'ARS'],
            'cotizacion' => ['type' => 'DECIMAL', 'constraint' => '18,6', 'default' => 1],
            'importe_ars' => ['type' => 'DECIMAL', 'constraint' => '18,4'],
            'observaciones' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'created_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['empresa_id', 'id'], 'uq_viaje_operativo_otros_haberes_empresa_id');
        $this->forge->addKey(['empresa_id', 'viaje_id'], false, false, 'idx_viaje_operativo_otros_haberes_viaje');
        $this->forge->addKey(['empresa_id', 'empleado_id', 'fecha'], false, false, 'idx_viaje_operativo_otros_haberes_empleado');
        $this->forge->addForeignKey('viaje_id', 'viajes_operativos', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('empresa_id', 'empresas', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey('sucursal_id', 'sucursales', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->addForeignKey('empleado_id', 'empleados', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->addForeignKey('created_by', 'usuarios', 'id', 'SET NULL', 'RESTRICT');
        $this->forge->createTable('viaje_operativo_otros_haberes', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('viaje_operativo_otros_haberes', true);
        $this->forge->dropColumn('viaje_operativo_gastos', 'pagado_por');
    }
}
