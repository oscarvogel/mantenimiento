<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Cuenta corriente multimoneda: saldos en moneda original, consolidación ARS y
 * diferencias de cambio (#588).
 *
 * - `cliente_cc_movimientos` (ampliada): el libro pasa a ser multi-moneda. Cada
 *   asiento guarda la moneda del movimiento, el importe en esa moneda y su
 *   cotización congelada, además del equivalente en pesos que ya tenía. El
 *   saldo en ARS sale de la diferencia entre Debe y Haber: no hay un campo de
 *   saldo que se pueda desincronizar.
 * - `cliente_cc_pagos`: el hecho económico de un cobro, en su propia moneda.
 *   No es un recibo: no lleva numeración, adjuntos ni datos de cheque. El
 *   recibo y el medio de pago (#536) se apoyan sobre esta capa, y por eso la
 *   tabla ya deja `recibo_id` y `recibo_medio_id` para esa costura.
 * - `cliente_cc_imputaciones`: la asignación de un cobro a un documento de
 *   deuda, **en la moneda de la deuda**. Guarda el valor histórico que le
 *   corresponde del documento, el valor del cobro a su cotización y la
 *   diferencia de cambio.
 *
 * Es aditiva: no toca ni borra cargos ni movimientos históricos, no inventa
 * monedas ni cotizaciones y no genera deuda retroactiva desde viajes.
 */
final class AddMultimonedaCustomerAccounts extends Migration
{
    private const PERMISSIONS = [
        'clientes.cc.cobrar' => 'Registrar, imputar y anular cobranzas de clientes',
    ];

    /** Movimientos del libro que no provienen de un cargo. */
    private const LEDGER_WITHOUT_CHARGE = 0;

    public function up(): void
    {
        $this->extendLedger();
        $this->createPayments();
        $this->createImputations();
        $this->backfillLedger();
        $this->grantPermissions();
    }

    public function down(): void
    {
        if ($this->db->tableExists('cliente_cc_imputaciones') && $this->db->table('cliente_cc_imputaciones')->countAllResults() > 0) {
            throw new \RuntimeException('Ya hay imputaciones de cobranza: no se revierte la cuenta corriente multimoneda.');
        }
        if ($this->db->tableExists('cliente_cc_pagos') && $this->db->table('cliente_cc_pagos')->countAllResults() > 0) {
            throw new \RuntimeException('Ya hay cobranzas registradas: no se revierte la cuenta corriente multimoneda.');
        }
        if ($this->db->tableExists('cliente_cc_movimientos')) {
            $linked = (int) $this->db->query(
                'SELECT COUNT(*) AS total FROM ' . $this->db->prefixTable('cliente_cc_movimientos') . ' WHERE pago_id IS NOT NULL',
            )->getRow('total');
            if ($linked > 0) {
                throw new \RuntimeException('El libro tiene asientos de cobranza: no se revierte la cuenta corriente multimoneda.');
            }
        }

        $this->forge->dropTable('cliente_cc_imputaciones', true);
        $this->forge->dropTable('cliente_cc_pagos', true);

        if ($this->db->tableExists('cliente_cc_movimientos')) {
            foreach (['imputacion_id', 'pago_id', 'cotizacion_fuente', 'cotizacion_fecha', 'cotizacion', 'haber_original', 'debe_original', 'moneda'] as $column) {
                if ($this->db->fieldExists($column, 'cliente_cc_movimientos')) {
                    $this->forge->dropColumn('cliente_cc_movimientos', $column);
                }
            }
        }

        $ids = array_map(
            static fn (array $row): int => (int) $row['id'],
            $this->db->table('permisos')->select('id')->whereIn('clave', array_keys(self::PERMISSIONS))->get()->getResultArray(),
        );
        if ($ids !== []) {
            $this->db->table('rol_permisos')->whereIn('permiso_id', $ids)->delete();
            $this->db->table('permisos')->whereIn('id', $ids)->delete();
        }
    }

    /** El libro pasa a llevar la moneda y el importe original de cada asiento. */
    private function extendLedger(): void
    {
        if (! $this->db->tableExists('cliente_cc_movimientos')) {
            return;
        }

        $columns = [
            'moneda' => ['type' => 'CHAR', 'constraint' => 3, 'null' => true],
            'debe_original' => ['type' => 'DECIMAL', 'constraint' => '18,4', 'null' => true],
            'haber_original' => ['type' => 'DECIMAL', 'constraint' => '18,4', 'null' => true],
            'cotizacion' => ['type' => 'DECIMAL', 'constraint' => '18,6', 'null' => true],
            'cotizacion_fecha' => ['type' => 'DATE', 'null' => true],
            'cotizacion_fuente' => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'pago_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'imputacion_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
        ];
        foreach ($columns as $name => $definition) {
            if (! $this->db->fieldExists($name, 'cliente_cc_movimientos')) {
                $this->forge->addColumn('cliente_cc_movimientos', [$name => $definition]);
            }
        }
        $this->db->resetDataCache();

        $this->createIndexIfMissing('cliente_cc_movimientos', 'idx_cliente_cc_movimientos_moneda', 'empresa_id, cliente_id, moneda');
    }

    /** El hecho económico de un cobro, en su propia moneda. */
    private function createPayments(): void
    {
        if ($this->db->tableExists('cliente_cc_pagos')) {
            return;
        }

        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'empresa_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'sucursal_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'cliente_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'fecha' => ['type' => 'DATE'],
            'moneda' => ['type' => 'CHAR', 'constraint' => 3],
            'importe' => ['type' => 'DECIMAL', 'constraint' => '18,4'],
            'cotizacion' => ['type' => 'DECIMAL', 'constraint' => '18,6'],
            'cotizacion_fecha' => ['type' => 'DATE'],
            'cotizacion_fuente' => ['type' => 'VARCHAR', 'constraint' => 20],
            'importe_ars' => ['type' => 'DECIMAL', 'constraint' => '18,4'],
            'medio' => ['type' => 'VARCHAR', 'constraint' => 20],
            'estado' => ['type' => 'VARCHAR', 'constraint' => 15, 'default' => 'VIGENTE'],
            'referencia' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'clave' => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => true],
            'observaciones' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'recibo_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'recibo_medio_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'acreditado_at' => ['type' => 'DATETIME', 'null' => true],
            'acreditado_por' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'motivo_anulacion' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'anulado_at' => ['type' => 'DATETIME', 'null' => true],
            'anulado_por' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'created_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['empresa_id', 'id'], 'uq_cliente_cc_pagos_empresa_id');
        $this->forge->addUniqueKey(['empresa_id', 'clave'], 'uq_cliente_cc_pagos_clave');
        $this->forge->addKey(['empresa_id', 'cliente_id', 'fecha'], false, false, 'idx_cliente_cc_pagos_cliente');
        $this->forge->addKey(['empresa_id', 'estado'], false, false, 'idx_cliente_cc_pagos_estado');
        $this->forge->addForeignKey('empresa_id', 'empresas', 'id', 'RESTRICT', 'RESTRICT', $this->fk('fk_cliente_cc_pagos_empresa'));
        $this->forge->addForeignKey(['empresa_id', 'cliente_id'], 'clientes', ['empresa_id', 'id'], 'RESTRICT', 'RESTRICT', $this->fk('fk_cliente_cc_pagos_cliente'));
        $this->forge->addForeignKey('created_by', 'usuarios', 'id', 'SET NULL', 'RESTRICT', $this->fk('fk_cliente_cc_pagos_autor'));
        $this->forge->addForeignKey('acreditado_por', 'usuarios', 'id', 'SET NULL', 'RESTRICT', $this->fk('fk_cliente_cc_pagos_acredita'));
        $this->forge->addForeignKey('anulado_por', 'usuarios', 'id', 'SET NULL', 'RESTRICT', $this->fk('fk_cliente_cc_pagos_anula'));
        $this->forge->createTable('cliente_cc_pagos', true);
    }

    /** La asignación de un cobro a un documento, en la moneda de la deuda. */
    private function createImputations(): void
    {
        if ($this->db->tableExists('cliente_cc_imputaciones')) {
            return;
        }

        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'empresa_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'cliente_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'pago_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'cargo_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'moneda' => ['type' => 'CHAR', 'constraint' => 3],
            'importe_original' => ['type' => 'DECIMAL', 'constraint' => '18,4'],
            'cotizacion_cargo' => ['type' => 'DECIMAL', 'constraint' => '18,6'],
            'importe_ars_cargo' => ['type' => 'DECIMAL', 'constraint' => '18,4'],
            'cotizacion_pago' => ['type' => 'DECIMAL', 'constraint' => '18,6'],
            'importe_ars_pago' => ['type' => 'DECIMAL', 'constraint' => '18,4'],
            'diferencia_cambio' => ['type' => 'DECIMAL', 'constraint' => '18,4'],
            'estado' => ['type' => 'VARCHAR', 'constraint' => 15, 'default' => 'VIGENTE'],
            'clave' => ['type' => 'VARCHAR', 'constraint' => 150],
            'motivo_anulacion' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'anulado_at' => ['type' => 'DATETIME', 'null' => true],
            'anulado_por' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'created_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['empresa_id', 'id'], 'uq_cliente_cc_imputaciones_empresa_id');
        $this->forge->addUniqueKey(['empresa_id', 'clave'], 'uq_cliente_cc_imputaciones_clave');
        $this->forge->addKey(['empresa_id', 'cargo_id', 'estado'], false, false, 'idx_cliente_cc_imputaciones_cargo');
        $this->forge->addKey(['empresa_id', 'cliente_id', 'estado'], false, false, 'idx_cliente_cc_imputaciones_cliente');
        $this->forge->addForeignKey('empresa_id', 'empresas', 'id', 'RESTRICT', 'RESTRICT', $this->fk('fk_cliente_cc_imputaciones_empresa'));
        $this->forge->addForeignKey(['empresa_id', 'cliente_id'], 'clientes', ['empresa_id', 'id'], 'RESTRICT', 'RESTRICT', $this->fk('fk_cliente_cc_imputaciones_cliente'));
        $this->forge->addForeignKey(['empresa_id', 'pago_id'], 'cliente_cc_pagos', ['empresa_id', 'id'], 'RESTRICT', 'RESTRICT', $this->fk('fk_cliente_cc_imputaciones_pago'));
        $this->forge->addForeignKey(['empresa_id', 'cargo_id'], 'cliente_cc_cargos', ['empresa_id', 'id'], 'RESTRICT', 'RESTRICT', $this->fk('fk_cliente_cc_imputaciones_cargo'));
        $this->forge->addForeignKey('created_by', 'usuarios', 'id', 'SET NULL', 'RESTRICT', $this->fk('fk_cliente_cc_imputaciones_autor'));
        $this->forge->addForeignKey('anulado_por', 'usuarios', 'id', 'SET NULL', 'RESTRICT', $this->fk('fk_cliente_cc_imputaciones_anula'));
        $this->forge->createTable('cliente_cc_imputaciones', true);
    }

    /**
     * Copia al libro la moneda y el importe original que ya tenía el cargo.
     *
     * Es una copia determinista: no recalcula importes, no inventa monedas ni
     * cotizaciones y no toca los valores históricos. Los asientos sin cargo
     * (hoy no hay ninguno) quedan en pesos con el importe original vacío.
     */
    private function backfillLedger(): void
    {
        if (! $this->db->tableExists('cliente_cc_movimientos') || ! $this->db->tableExists('cliente_cc_cargos')) {
            return;
        }

        $rows = $this->db->table('cliente_cc_movimientos m')
            ->select('m.id, m.empresa_id, m.tipo, m.cargo_id, c.sentido, c.moneda, c.importe_original, c.cotizacion, c.cotizacion_fecha, c.cotizacion_fuente')
            ->join('cliente_cc_cargos c', 'c.empresa_id = m.empresa_id AND c.id = m.cargo_id', 'left')
            ->orderBy('m.id', 'ASC')
            ->get()
            ->getResultArray();

        $orphans = 0;
        foreach ($rows as $row) {
            if ($row['cargo_id'] === null || $row['moneda'] === null) {
                $orphans++;
                $this->db->table('cliente_cc_movimientos')
                    ->where('id', (int) $row['id'])
                    ->update(['moneda' => 'ARS', 'debe_original' => 0, 'haber_original' => 0]);
                continue;
            }

            $isAnnullment = (string) $row['tipo'] === 'ANULACION';
            $isDebit = (string) $row['sentido'] === 'DEBE';
            $debe = ($isDebit !== $isAnnullment) ? $row['importe_original'] : 0;
            $haber = ($isDebit !== $isAnnullment) ? 0 : $row['importe_original'];

            $this->db->table('cliente_cc_movimientos')
                ->where('id', (int) $row['id'])
                ->update([
                    'moneda' => (string) $row['moneda'],
                    'debe_original' => $debe,
                    'haber_original' => $haber,
                    'cotizacion' => $row['cotizacion'],
                    'cotizacion_fecha' => $row['cotizacion_fecha'],
                    'cotizacion_fuente' => $row['cotizacion_fuente'],
                ]);
        }

        if ($orphans > self::LEDGER_WITHOUT_CHARGE) {
            log_message('warning', 'La cuenta corriente tiene {count} asientos sin cargo: se dejaron en pesos sin importe original.', ['count' => $orphans]);
        }
    }

    /** SQLite (pruebas) no admite nombres de foreign key; MySQL los conserva. */
    private function fk(string $name): string
    {
        return $this->db->DBDriver === 'SQLite3' ? '' : $name;
    }

    private function createIndexIfMissing(string $table, string $name, string $columns): void
    {
        foreach ($this->db->getIndexData($table) as $index) {
            $data = (array) $index;
            if (($data['name'] ?? '') === $name) {
                return;
            }
        }
        $this->db->query('CREATE INDEX ' . $this->db->escapeIdentifiers($name) . ' ON ' . $this->db->prefixTable($table) . ' (' . $columns . ')');
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

        if ($permissionIds['clientes.cc.cobrar'] !== 0) {
            foreach ($administrators as $roleId) {
                $relation = ['rol_id' => $roleId, 'permiso_id' => $permissionIds['clientes.cc.cobrar']];
                if (! $this->db->table('rol_permisos')->where($relation)->countAllResults()) {
                    $this->db->table('rol_permisos')->insert($relation + ['created_at' => $now]);
                }
            }
        }
    }
}
