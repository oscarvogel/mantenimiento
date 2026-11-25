<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Padrón de neumáticos, catálogos, bitácora de estados y depósitos
 * (especificación §5.12).
 *
 * Decisiones:
 * - Marcas, modelos y tipos de cubierta son por empresa, como los catálogos de
 *   equipos. Las claves foráneas compuestas (`empresa_id`, id) impiden que un
 *   neumático use el catálogo de otra empresa.
 * - `neumaticos_estados` es un catálogo de referencia de los 11 códigos. Las
 *   transiciones no se guardan en la tabla: las decide el dominio
 *   (`NeumaticStatus`), para no tener dos fuentes de verdad.
 * - Las colocaciones (`neumatico_ubicaciones`, `neumatico_colocaciones`) llegan
 *   con la colocación y la extracción, que generan lecturas del equipo.
 * - Un neumático no se borra: se da de baja. No hay `deleted_at`.
 */
final class CreateNeumaticosTables extends Migration
{
    /** @var array<string, string> */
    private const STATES = [
        'NUEVA' => 'Nueva',
        'MEDIO_USO' => '1/2 uso',
        'EN_REPARACION' => 'En reparación',
        'REPARADO' => 'Reparado',
        'RECAPADA' => 'Recapada',
        'VULCANIZADA' => 'Vulcanizada',
        'DESCARTE' => 'Descarte',
        'BAJA_DEFINITIVA' => 'Baja definitiva',
        'TRANSFERIDA' => 'Transferida',
        'PARA_VENTA' => 'Para venta',
        'VENDIDA' => 'Vendida',
    ];

    public function up(): void
    {
        $this->catalog('neumatico_marcas', 'nombre', 60);

        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'empresa_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'marca_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'nombre' => ['type' => 'VARCHAR', 'constraint' => 60],
            'activo' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['marca_id', 'nombre'], 'uq_neumatico_modelos_marca_nombre');
        $this->forge->addUniqueKey(['empresa_id', 'id'], 'uq_neumatico_modelos_empresa_id');
        $this->forge->addForeignKey(['empresa_id', 'marca_id'], 'neumatico_marcas', ['empresa_id', 'id'], 'RESTRICT', 'RESTRICT', $this->fk('fk_neumatico_modelos_marca'));
        $this->forge->createTable('neumatico_modelos', true);

        $this->catalog('neumatico_tipos_cubierta', 'descripcion', 40);

        $this->forge->addField([
            'codigo' => ['type' => 'VARCHAR', 'constraint' => 20],
            'nombre' => ['type' => 'VARCHAR', 'constraint' => 40],
        ]);
        $this->forge->addKey('codigo', true);
        $this->forge->createTable('neumaticos_estados', true);
        foreach (self::STATES as $code => $name) {
            if (! $this->db->table('neumaticos_estados')->where('codigo', $code)->countAllResults()) {
                $this->db->table('neumaticos_estados')->insert(['codigo' => $code, 'nombre' => $name]);
            }
        }

        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'empresa_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'sucursal_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'numero' => ['type' => 'VARCHAR', 'constraint' => 20],
            'marca_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'modelo_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'tipo_cubierta_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'medida' => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true],
            'anio_fabricacion' => ['type' => 'SMALLINT', 'constraint' => 4, 'unsigned' => true, 'null' => true],
            'estado' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'NUEVA'],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
            'created_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'updated_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['empresa_id', 'numero'], 'uq_neumaticos_empresa_numero');
        $this->forge->addUniqueKey(['empresa_id', 'id'], 'uq_neumaticos_empresa_id');
        $this->forge->addKey(['empresa_id', 'sucursal_id', 'estado'], false, false, 'idx_neumaticos_padron');
        $this->forge->addForeignKey('empresa_id', 'empresas', 'id', 'RESTRICT', 'RESTRICT', $this->fk('fk_neumaticos_empresa'));
        $this->forge->addForeignKey(['empresa_id', 'sucursal_id'], 'sucursales', ['empresa_id', 'id'], 'RESTRICT', 'RESTRICT', $this->fk('fk_neumaticos_sucursal'));
        $this->forge->addForeignKey(['empresa_id', 'marca_id'], 'neumatico_marcas', ['empresa_id', 'id'], 'RESTRICT', 'RESTRICT', $this->fk('fk_neumaticos_marca'));
        $this->forge->addForeignKey(['empresa_id', 'modelo_id'], 'neumatico_modelos', ['empresa_id', 'id'], 'RESTRICT', 'RESTRICT', $this->fk('fk_neumaticos_modelo'));
        $this->forge->addForeignKey(['empresa_id', 'tipo_cubierta_id'], 'neumatico_tipos_cubierta', ['empresa_id', 'id'], 'RESTRICT', 'RESTRICT', $this->fk('fk_neumaticos_tipo_cubierta'));
        $this->forge->addForeignKey('estado', 'neumaticos_estados', 'codigo', 'RESTRICT', 'RESTRICT', $this->fk('fk_neumaticos_estado'));
        $this->forge->addForeignKey('created_by', 'usuarios', 'id', 'SET NULL', 'RESTRICT', $this->fk('fk_neumaticos_created_by'));
        $this->forge->addForeignKey('updated_by', 'usuarios', 'id', 'SET NULL', 'RESTRICT', $this->fk('fk_neumaticos_updated_by'));
        $this->forge->createTable('neumaticos', true);

        // Bitácora de estados: append-only. Reemplaza `historial_estado_cubiertas`.
        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'empresa_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'neumatico_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'estado_anterior' => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'estado' => ['type' => 'VARCHAR', 'constraint' => 20],
            'fecha' => ['type' => 'DATETIME'],
            'motivo' => ['type' => 'VARCHAR', 'constraint' => 300],
            'usuario_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['empresa_id', 'neumatico_id', 'fecha'], false, false, 'idx_neumatico_estado_historial');
        $this->forge->addForeignKey(['empresa_id', 'neumatico_id'], 'neumaticos', ['empresa_id', 'id'], 'RESTRICT', 'RESTRICT', $this->fk('fk_neumatico_estado_historial_neumatico'));
        $this->forge->addForeignKey('estado_anterior', 'neumaticos_estados', 'codigo', 'RESTRICT', 'RESTRICT', $this->fk('fk_neumatico_estado_historial_anterior'));
        $this->forge->addForeignKey('estado', 'neumaticos_estados', 'codigo', 'RESTRICT', 'RESTRICT', $this->fk('fk_neumatico_estado_historial_estado'));
        $this->forge->addForeignKey('usuario_id', 'usuarios', 'id', 'SET NULL', 'RESTRICT', $this->fk('fk_neumatico_estado_historial_usuario'));
        $this->forge->createTable('neumatico_estado_historial', true);

        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'empresa_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'sucursal_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'nombre' => ['type' => 'VARCHAR', 'constraint' => 80],
            'ubicacion' => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true],
            'activo' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['empresa_id', 'nombre'], 'uq_depositos_empresa_nombre');
        $this->forge->addUniqueKey(['empresa_id', 'id'], 'uq_depositos_empresa_id');
        $this->forge->addForeignKey(['empresa_id', 'sucursal_id'], 'sucursales', ['empresa_id', 'id'], 'RESTRICT', 'RESTRICT', $this->fk('fk_depositos_sucursal'));
        $this->forge->createTable('depositos', true);

        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'empresa_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'deposito_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'neumatico_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'cantidad' => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'actualizado_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['deposito_id', 'neumatico_id'], 'uq_deposito_stock_neumatico');
        $this->forge->addForeignKey(['empresa_id', 'deposito_id'], 'depositos', ['empresa_id', 'id'], 'RESTRICT', 'RESTRICT', $this->fk('fk_deposito_stock_deposito'));
        $this->forge->addForeignKey(['empresa_id', 'neumatico_id'], 'neumaticos', ['empresa_id', 'id'], 'RESTRICT', 'RESTRICT', $this->fk('fk_deposito_stock_neumatico'));
        $this->forge->createTable('deposito_stock', true);

        // Movimientos: append-only. Explican cada cambio de `deposito_stock`.
        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'empresa_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'deposito_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'neumatico_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'tipo' => ['type' => 'VARCHAR', 'constraint' => 10],
            'fecha' => ['type' => 'DATETIME'],
            'numero_planilla' => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true],
            'cantidad' => ['type' => 'INT', 'constraint' => 11],
            'usuario_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'observaciones' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['empresa_id', 'deposito_id', 'fecha'], false, false, 'idx_deposito_movimientos_deposito');
        $this->forge->addKey(['empresa_id', 'neumatico_id', 'fecha'], false, false, 'idx_deposito_movimientos_neumatico');
        $this->forge->addForeignKey(['empresa_id', 'deposito_id'], 'depositos', ['empresa_id', 'id'], 'RESTRICT', 'RESTRICT', $this->fk('fk_deposito_movimientos_deposito'));
        $this->forge->addForeignKey(['empresa_id', 'neumatico_id'], 'neumaticos', ['empresa_id', 'id'], 'RESTRICT', 'RESTRICT', $this->fk('fk_deposito_movimientos_neumatico'));
        $this->forge->addForeignKey('usuario_id', 'usuarios', 'id', 'SET NULL', 'RESTRICT', $this->fk('fk_deposito_movimientos_usuario'));
        $this->forge->createTable('deposito_movimientos', true);
    }

    public function down(): void
    {
        foreach ([
            'deposito_movimientos',
            'deposito_stock',
            'depositos',
            'neumatico_estado_historial',
            'neumaticos',
            'neumaticos_estados',
            'neumatico_tipos_cubierta',
            'neumatico_modelos',
            'neumatico_marcas',
        ] as $table) {
            $this->forge->dropTable($table, true);
        }
    }

    /**
     * SQLite no acepta nombres de foreign key; MySQL sí, y el nombre hace
     * legible el error cuando una restricción salta.
     */
    private function fk(string $name): string
    {
        return $this->db->DBDriver === 'SQLite3' ? '' : $name;
    }

    private function catalog(string $table, string $nameColumn, int $length): void
    {
        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'empresa_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            $nameColumn => ['type' => 'VARCHAR', 'constraint' => $length],
            'activo' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['empresa_id', $nameColumn], 'uq_' . $table . '_empresa_' . $nameColumn);
        $this->forge->addUniqueKey(['empresa_id', 'id'], 'uq_' . $table . '_empresa_id');
        $this->forge->addForeignKey('empresa_id', 'empresas', 'id', 'RESTRICT', 'RESTRICT', $this->fk('fk_' . $table . '_empresa'));
        $this->forge->createTable($table, true);
    }
}
