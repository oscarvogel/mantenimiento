<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Cuenta corriente de clientes en ARS — etapa 1 (#530).
 *
 * - `cliente_cc_cargos`: la obligación comercial. Nace una sola vez por tramo
 *   (memo autorizado, factura emitida o cierre comercial aprobado) o como saldo
 *   inicial del cliente. Guarda importe y moneda originales, la cotización
 *   congelada con su fecha y fuente, y la estimación que tenía el viaje. El
 *   saldo inicial puede ser a cobrar (`sentido` DEBE) o a favor (HABER).
 *   `clave_vigente` hace cumplir "un cargo vigente por tramo" (y un saldo
 *   inicial por cliente): al anularse queda en NULL y el tramo se puede volver
 *   a formalizar sin perder el cargo anulado.
 * - `cliente_cc_movimientos`: el libro en ARS, solo se agrega. El saldo se
 *   deriva de Debe − Haber; no hay un campo de saldo mutable. `clave` vuelve
 *   idempotente cada asiento.
 * - `cliente_cc_tramos_no_cobrables`: cierre comercial sin deuda, con motivo.
 *
 * Es aditiva: no toca viajes, tramos ni clientes y no genera cargos de viajes
 * históricos.
 */
final class CreateCustomerAccounts extends Migration
{
    private const PERMISSIONS = [
        'clientes.cc.ver' => 'Consultar la cuenta corriente de clientes y el control de cierre comercial',
        'clientes.cc.formalizar' => 'Formalizar el cobro de tramos, registrar saldos iniciales y anular cargos de clientes',
    ];

    /** El permiso de consulta lo reciben además los roles que ya ven el económico de Viajes. */
    private const VIEW_GRANTED_WHEN_ROLE_HAS = ['viajes.economico.ver'];

    public function up(): void
    {
        if (! $this->db->tableExists('cliente_cc_cargos')) {
            $this->forge->addField([
                'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
                'empresa_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
                'cliente_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
                'sucursal_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
                'viaje_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
                'tramo_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
                'modalidad' => ['type' => 'VARCHAR', 'constraint' => 30],
                'sentido' => ['type' => 'VARCHAR', 'constraint' => 5, 'default' => 'DEBE'],
                'documento_tipo' => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
                'documento_numero' => ['type' => 'VARCHAR', 'constraint' => 60, 'null' => true],
                'documento_fecha' => ['type' => 'DATE'],
                'importe_original' => ['type' => 'DECIMAL', 'constraint' => '18,4'],
                'moneda' => ['type' => 'CHAR', 'constraint' => 3],
                'cotizacion' => ['type' => 'DECIMAL', 'constraint' => '18,6'],
                'cotizacion_fecha' => ['type' => 'DATE'],
                'cotizacion_fuente' => ['type' => 'VARCHAR', 'constraint' => 20],
                'importe_ars' => ['type' => 'DECIMAL', 'constraint' => '18,4'],
                'importe_estimado' => ['type' => 'DECIMAL', 'constraint' => '18,4', 'null' => true],
                'moneda_estimada' => ['type' => 'CHAR', 'constraint' => 3, 'null' => true],
                'cotizacion_estimada' => ['type' => 'DECIMAL', 'constraint' => '18,6', 'null' => true],
                'importe_estimado_ars' => ['type' => 'DECIMAL', 'constraint' => '18,4', 'null' => true],
                'observaciones' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
                'estado' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'VIGENTE'],
                'clave_vigente' => ['type' => 'VARCHAR', 'constraint' => 80, 'null' => true],
                'motivo_anulacion' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
                'anulado_at' => ['type' => 'DATETIME', 'null' => true],
                'anulado_por' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
                'created_at' => ['type' => 'DATETIME', 'null' => true],
                'created_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addUniqueKey(['empresa_id', 'id'], 'uq_cliente_cc_cargos_empresa_id');
            $this->forge->addUniqueKey(['empresa_id', 'clave_vigente'], 'uq_cliente_cc_cargos_vigente');
            $this->forge->addKey(['empresa_id', 'cliente_id', 'estado'], false, false, 'idx_cliente_cc_cargos_cliente');
            $this->forge->addKey(['empresa_id', 'tramo_id'], false, false, 'idx_cliente_cc_cargos_tramo');
            $this->forge->addForeignKey('empresa_id', 'empresas', 'id', 'RESTRICT', 'RESTRICT', $this->fk('fk_cliente_cc_cargos_empresa'));
            $this->forge->addForeignKey(['empresa_id', 'cliente_id'], 'clientes', ['empresa_id', 'id'], 'RESTRICT', 'RESTRICT', $this->fk('fk_cliente_cc_cargos_cliente'));
            $this->forge->addForeignKey(['empresa_id', 'tramo_id'], 'viaje_operativo_tramos', ['empresa_id', 'id'], 'RESTRICT', 'RESTRICT', $this->fk('fk_cliente_cc_cargos_tramo'));
            $this->forge->addForeignKey('created_by', 'usuarios', 'id', 'SET NULL', 'RESTRICT', $this->fk('fk_cliente_cc_cargos_autor'));
            $this->forge->addForeignKey('anulado_por', 'usuarios', 'id', 'SET NULL', 'RESTRICT', $this->fk('fk_cliente_cc_cargos_anulador'));
            $this->forge->createTable('cliente_cc_cargos', true);
        }

        if (! $this->db->tableExists('cliente_cc_movimientos')) {
            $this->forge->addField([
                'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
                'empresa_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
                'cliente_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
                'fecha' => ['type' => 'DATE'],
                'tipo' => ['type' => 'VARCHAR', 'constraint' => 30],
                'cargo_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
                'debe_ars' => ['type' => 'DECIMAL', 'constraint' => '18,4', 'default' => 0],
                'haber_ars' => ['type' => 'DECIMAL', 'constraint' => '18,4', 'default' => 0],
                'descripcion' => ['type' => 'VARCHAR', 'constraint' => 255],
                'clave' => ['type' => 'VARCHAR', 'constraint' => 100],
                'created_at' => ['type' => 'DATETIME', 'null' => true],
                'created_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addUniqueKey(['empresa_id', 'clave'], 'uq_cliente_cc_movimientos_clave');
            $this->forge->addKey(['empresa_id', 'cliente_id', 'fecha'], false, false, 'idx_cliente_cc_movimientos_cliente');
            $this->forge->addForeignKey('empresa_id', 'empresas', 'id', 'RESTRICT', 'RESTRICT', $this->fk('fk_cliente_cc_movimientos_empresa'));
            $this->forge->addForeignKey(['empresa_id', 'cliente_id'], 'clientes', ['empresa_id', 'id'], 'RESTRICT', 'RESTRICT', $this->fk('fk_cliente_cc_movimientos_cliente'));
            $this->forge->addForeignKey(['empresa_id', 'cargo_id'], 'cliente_cc_cargos', ['empresa_id', 'id'], 'RESTRICT', 'RESTRICT', $this->fk('fk_cliente_cc_movimientos_cargo'));
            $this->forge->addForeignKey('created_by', 'usuarios', 'id', 'SET NULL', 'RESTRICT', $this->fk('fk_cliente_cc_movimientos_autor'));
            $this->forge->createTable('cliente_cc_movimientos', true);
        }

        if (! $this->db->tableExists('cliente_cc_tramos_no_cobrables')) {
            $this->forge->addField([
                'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
                'empresa_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
                'sucursal_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
                'viaje_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
                'tramo_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
                'motivo' => ['type' => 'VARCHAR', 'constraint' => 500],
                'estado' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'VIGENTE'],
                'clave_vigente' => ['type' => 'VARCHAR', 'constraint' => 80, 'null' => true],
                'motivo_reapertura' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
                'reabierto_at' => ['type' => 'DATETIME', 'null' => true],
                'reabierto_por' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
                'created_at' => ['type' => 'DATETIME', 'null' => true],
                'created_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addUniqueKey(['empresa_id', 'clave_vigente'], 'uq_cliente_cc_no_cobrables_vigente');
            $this->forge->addKey(['empresa_id', 'tramo_id'], false, false, 'idx_cliente_cc_no_cobrables_tramo');
            $this->forge->addForeignKey('empresa_id', 'empresas', 'id', 'RESTRICT', 'RESTRICT', $this->fk('fk_cliente_cc_no_cobrables_empresa'));
            $this->forge->addForeignKey(['empresa_id', 'tramo_id'], 'viaje_operativo_tramos', ['empresa_id', 'id'], 'RESTRICT', 'RESTRICT', $this->fk('fk_cliente_cc_no_cobrables_tramo'));
            $this->forge->addForeignKey('created_by', 'usuarios', 'id', 'SET NULL', 'RESTRICT', $this->fk('fk_cliente_cc_no_cobrables_autor'));
            $this->forge->addForeignKey('reabierto_por', 'usuarios', 'id', 'SET NULL', 'RESTRICT', $this->fk('fk_cliente_cc_no_cobrables_reabrio'));
            $this->forge->createTable('cliente_cc_tramos_no_cobrables', true);
        }

        $this->grantPermissions();
    }

    public function down(): void
    {
        // Solo se deshace si todavía no hay datos: la cuenta corriente no se borra.
        foreach (['cliente_cc_movimientos', 'cliente_cc_cargos', 'cliente_cc_tramos_no_cobrables'] as $table) {
            if ($this->db->tableExists($table) && $this->db->table($table)->countAllResults() > 0) {
                throw new \RuntimeException('La tabla ' . $table . ' ya tiene movimientos: no se revierte la cuenta corriente.');
            }
        }

        $this->forge->dropTable('cliente_cc_movimientos', true);
        $this->forge->dropTable('cliente_cc_tramos_no_cobrables', true);
        $this->forge->dropTable('cliente_cc_cargos', true);

        $ids = array_map(
            static fn (array $row): int => (int) $row['id'],
            $this->db->table('permisos')->select('id')->whereIn('clave', array_keys(self::PERMISSIONS))->get()->getResultArray(),
        );
        if ($ids !== []) {
            $this->db->table('rol_permisos')->whereIn('permiso_id', $ids)->delete();
            $this->db->table('permisos')->whereIn('id', $ids)->delete();
        }
    }

    /** SQLite (pruebas) no admite nombres de foreign key; MySQL los conserva. */
    private function fk(string $name): string
    {
        return $this->db->DBDriver === 'SQLite3' ? '' : $name;
    }

    private function grantPermissions(): void
    {
        $now = date('Y-m-d H:i:s');
        $permissionIds = [];
        foreach (self::PERMISSIONS as $key => $description) {
            if (! $this->db->table('permisos')->where('clave', $key)->countAllResults()) {
                $this->db->table('permisos')->insert(['clave' => $key, 'descripcion' => $description, 'created_at' => $now, 'updated_at' => $now]);
            }
            $row = $this->db->table('permisos')->select('id')->where('clave', $key)->get()->getRowArray();
            $permissionIds[$key] = (int) ($row['id'] ?? 0);
        }

        $administrators = array_map(
            static fn (array $row): int => (int) $row['id'],
            $this->db->table('roles')->select('id')->where('nombre', 'Administrador')->get()->getResultArray(),
        );
        $viewers = $administrators;
        $sourceIds = array_map(
            static fn (array $row): int => (int) $row['id'],
            $this->db->table('permisos')->select('id')->whereIn('clave', self::VIEW_GRANTED_WHEN_ROLE_HAS)->get()->getResultArray(),
        );
        if ($sourceIds !== []) {
            foreach ($this->db->table('rol_permisos')->select('rol_id')->whereIn('permiso_id', $sourceIds)->get()->getResultArray() as $row) {
                $viewers[] = (int) $row['rol_id'];
            }
        }

        $grants = [
            'clientes.cc.ver' => array_unique($viewers),
            'clientes.cc.formalizar' => array_unique($administrators),
        ];
        foreach ($grants as $key => $roleIds) {
            if ($permissionIds[$key] === 0) {
                continue;
            }
            foreach ($roleIds as $roleId) {
                $relation = ['rol_id' => $roleId, 'permiso_id' => $permissionIds[$key]];
                if (! $this->db->table('rol_permisos')->where($relation)->countAllResults()) {
                    $this->db->table('rol_permisos')->insert($relation + ['created_at' => $now]);
                }
            }
        }
    }
}
