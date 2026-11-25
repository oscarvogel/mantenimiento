<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Recibos y cobranzas de clientes — etapa 2 (#536).
 *
 * - `cliente_recibos`: cabecera del recibo. En BORRADOR no tiene número; al
 *   confirmarse consume el correlativo por empresa, sucursal y serie.
 * - `cliente_recibo_medios`: cada medio de pago con su moneda, importe original
 *   y cotización congelada. `acredita_al_confirmar` decide si impacta el libro
 *   al confirmar o si queda pendiente hasta una acreditación explícita.
 * - `cliente_cheques`: identidad del cheque físico/eCheq, separada del medio
 *   para que el #537 gestione cartera, endosos y depósitos sin duplicar datos.
 * - `cliente_recibo_imputaciones`: asignación del crédito a cargos concretos.
 *   No genera asientos: el Haber nace al acreditarse el medio.
 * - `cliente_recibo_adjuntos`: comprobantes privados del recibo.
 * - `cliente_recibos_numeracion`: contador correlativo bloqueado.
 * - `cliente_cc_movimientos` suma `recibo_id` y `recibo_medio_id` para trazar
 *   cada cobro y su contraasiento sobre el libro existente.
 *
 * Es aditiva: no modifica ni borra movimientos históricos de la etapa #530.
 */
final class CreateCustomerReceipts extends Migration
{
    private const PERMISSIONS = [
        'clientes.recibos.ver' => 'Consultar recibos de cobranza y documentos pendientes de cobro',
        'clientes.recibos.registrar' => 'Registrar recibos de cobranza en borrador',
        'clientes.recibos.confirmar' => 'Confirmar recibos y acreditar medios de pago',
        'clientes.recibos.anular' => 'Anular recibos de cobranza con contraasientos',
        'clientes.recibos.imputar' => 'Imputar pagos y anticipos a documentos del cliente',
        'clientes.recibos.imprimir' => 'Imprimir o descargar recibos de cobranza',
    ];

    public function up(): void
    {
        if (! $this->db->tableExists('cliente_recibos_numeracion')) {
            $this->forge->addField([
                'empresa_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
                'sucursal_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
                'serie' => ['type' => 'VARCHAR', 'constraint' => 10],
                'ultimo_numero' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'default' => 0],
                'updated_at' => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addKey(['empresa_id', 'sucursal_id', 'serie'], true);
            $this->forge->createTable('cliente_recibos_numeracion', true);
        }

        if (! $this->db->tableExists('cliente_recibos')) {
            $this->forge->addField([
                'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
                'empresa_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
                'sucursal_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
                'serie' => ['type' => 'VARCHAR', 'constraint' => 10],
                'numero' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
                'cliente_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
                'fecha_emision' => ['type' => 'DATE'],
                'fecha_recepcion' => ['type' => 'DATE'],
                'estado' => ['type' => 'VARCHAR', 'constraint' => 15, 'default' => 'BORRADOR'],
                'observaciones' => ['type' => 'VARCHAR', 'constraint' => 1000, 'null' => true],
                'total_ars' => ['type' => 'DECIMAL', 'constraint' => '18,4', 'default' => 0],
                'total_acreditado_ars' => ['type' => 'DECIMAL', 'constraint' => '18,4', 'default' => 0],
                'total_imputado_ars' => ['type' => 'DECIMAL', 'constraint' => '18,4', 'default' => 0],
                'usuario_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
                'confirmado_at' => ['type' => 'DATETIME', 'null' => true],
                'confirmado_por' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
                'anulado_at' => ['type' => 'DATETIME', 'null' => true],
                'anulado_por' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
                'motivo_anulacion' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
                'created_at' => ['type' => 'DATETIME', 'null' => true],
                'updated_at' => ['type' => 'DATETIME', 'null' => true],
                'created_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addUniqueKey(['empresa_id', 'id'], 'uq_cliente_recibos_empresa_id');
            $this->forge->addUniqueKey(['empresa_id', 'sucursal_id', 'serie', 'numero'], 'uq_cliente_recibos_numero');
            $this->forge->addKey(['empresa_id', 'cliente_id', 'fecha_emision'], false, false, 'idx_cliente_recibos_cliente');
            $this->forge->addKey(['empresa_id', 'estado', 'fecha_emision'], false, false, 'idx_cliente_recibos_estado');
            $this->forge->addForeignKey('empresa_id', 'empresas', 'id', 'RESTRICT', 'RESTRICT', $this->fk('fk_cliente_recibos_empresa'));
            $this->forge->addForeignKey(['empresa_id', 'cliente_id'], 'clientes', ['empresa_id', 'id'], 'RESTRICT', 'RESTRICT', $this->fk('fk_cliente_recibos_cliente'));
            $this->forge->addForeignKey('usuario_id', 'usuarios', 'id', 'RESTRICT', 'RESTRICT', $this->fk('fk_cliente_recibos_usuario'));
            $this->forge->addForeignKey('confirmado_por', 'usuarios', 'id', 'SET NULL', 'RESTRICT', $this->fk('fk_cliente_recibos_confirma'));
            $this->forge->addForeignKey('anulado_por', 'usuarios', 'id', 'SET NULL', 'RESTRICT', $this->fk('fk_cliente_recibos_anula'));
            $this->forge->createTable('cliente_recibos', true);
        }

        if (! $this->db->tableExists('cliente_recibo_medios')) {
            $this->forge->addField([
                'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
                'empresa_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
                'recibo_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
                'orden' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
                'medio' => ['type' => 'VARCHAR', 'constraint' => 20],
                'moneda' => ['type' => 'CHAR', 'constraint' => 3],
                'importe' => ['type' => 'DECIMAL', 'constraint' => '18,4'],
                'cotizacion' => ['type' => 'DECIMAL', 'constraint' => '18,6'],
                'cotizacion_fecha' => ['type' => 'DATE'],
                'cotizacion_fuente' => ['type' => 'VARCHAR', 'constraint' => 20],
                'importe_ars' => ['type' => 'DECIMAL', 'constraint' => '18,4'],
                'acredita_al_confirmar' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
                'estado_acreditacion' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'PENDIENTE'],
                'acreditado_at' => ['type' => 'DATETIME', 'null' => true],
                'acreditado_por' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
                'banco' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
                'cuenta_destino' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
                'titular' => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => true],
                'fecha_transferencia' => ['type' => 'DATE', 'null' => true],
                'referencia' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
                'estado_verificacion' => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
                'conciliado_at' => ['type' => 'DATETIME', 'null' => true],
                'conciliado_por' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
                'observaciones' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
                'created_at' => ['type' => 'DATETIME', 'null' => true],
                'updated_at' => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addUniqueKey(['empresa_id', 'id'], 'uq_cliente_recibo_medios_empresa_id');
            $this->forge->addUniqueKey(['empresa_id', 'recibo_id', 'orden'], 'uq_cliente_recibo_medios_orden');
            $this->forge->addKey(['empresa_id', 'estado_acreditacion'], false, false, 'idx_cliente_recibo_medios_estado');
            $this->forge->addForeignKey('empresa_id', 'empresas', 'id', 'RESTRICT', 'RESTRICT', $this->fk('fk_cliente_recibo_medios_empresa'));
            $this->forge->addForeignKey(['empresa_id', 'recibo_id'], 'cliente_recibos', ['empresa_id', 'id'], 'RESTRICT', 'RESTRICT', $this->fk('fk_cliente_recibo_medios_recibo'));
            $this->forge->addForeignKey('acreditado_por', 'usuarios', 'id', 'SET NULL', 'RESTRICT', $this->fk('fk_cliente_recibo_medios_acredita'));
            $this->forge->createTable('cliente_recibo_medios', true);
        }

        if (! $this->db->tableExists('cliente_cheques')) {
            $this->forge->addField([
                'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
                'empresa_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
                'recibo_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
                'recibo_medio_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
                'tipo' => ['type' => 'VARCHAR', 'constraint' => 20],
                'banco_emisor' => ['type' => 'VARCHAR', 'constraint' => 100],
                'numero' => ['type' => 'VARCHAR', 'constraint' => 60],
                'librador' => ['type' => 'VARCHAR', 'constraint' => 150],
                'cuit_librador' => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
                'fecha_emision' => ['type' => 'DATE'],
                'fecha_pago' => ['type' => 'DATE'],
                'fecha_recepcion' => ['type' => 'DATE'],
                'moneda' => ['type' => 'CHAR', 'constraint' => 3],
                'importe' => ['type' => 'DECIMAL', 'constraint' => '18,4'],
                'cotizacion' => ['type' => 'DECIMAL', 'constraint' => '18,6'],
                'importe_ars' => ['type' => 'DECIMAL', 'constraint' => '18,4'],
                'estado' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'EN_CARTERA'],
                'observaciones' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
                'created_at' => ['type' => 'DATETIME', 'null' => true],
                'updated_at' => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addUniqueKey(['empresa_id', 'recibo_medio_id'], 'uq_cliente_cheques_medio');
            $this->forge->addKey(['empresa_id', 'estado', 'fecha_pago'], false, false, 'idx_cliente_cheques_cartera');
            $this->forge->addForeignKey('empresa_id', 'empresas', 'id', 'RESTRICT', 'RESTRICT', $this->fk('fk_cliente_cheques_empresa'));
            $this->forge->addForeignKey(['empresa_id', 'recibo_id'], 'cliente_recibos', ['empresa_id', 'id'], 'RESTRICT', 'RESTRICT', $this->fk('fk_cliente_cheques_recibo'));
            $this->forge->addForeignKey(['empresa_id', 'recibo_medio_id'], 'cliente_recibo_medios', ['empresa_id', 'id'], 'RESTRICT', 'RESTRICT', $this->fk('fk_cliente_cheques_medio'));
            $this->forge->createTable('cliente_cheques', true);
        }

        if (! $this->db->tableExists('cliente_recibo_imputaciones')) {
            $this->forge->addField([
                'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
                'empresa_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
                'recibo_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
                'cargo_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
                'importe_ars' => ['type' => 'DECIMAL', 'constraint' => '18,4'],
                'estado' => ['type' => 'VARCHAR', 'constraint' => 15, 'default' => 'VIGENTE'],
                'created_at' => ['type' => 'DATETIME', 'null' => true],
                'updated_at' => ['type' => 'DATETIME', 'null' => true],
                'created_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
                'anulada_at' => ['type' => 'DATETIME', 'null' => true],
                'anulada_por' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addUniqueKey(['empresa_id', 'recibo_id', 'cargo_id'], 'uq_cliente_recibo_imputacion');
            $this->forge->addKey(['empresa_id', 'cargo_id', 'estado'], false, false, 'idx_cliente_recibo_imputacion_cargo');
            $this->forge->addForeignKey('empresa_id', 'empresas', 'id', 'RESTRICT', 'RESTRICT', $this->fk('fk_cliente_recibo_imputaciones_empresa'));
            $this->forge->addForeignKey(['empresa_id', 'recibo_id'], 'cliente_recibos', ['empresa_id', 'id'], 'RESTRICT', 'RESTRICT', $this->fk('fk_cliente_recibo_imputaciones_recibo'));
            $this->forge->addForeignKey(['empresa_id', 'cargo_id'], 'cliente_cc_cargos', ['empresa_id', 'id'], 'RESTRICT', 'RESTRICT', $this->fk('fk_cliente_recibo_imputaciones_cargo'));
            $this->forge->createTable('cliente_recibo_imputaciones', true);
        }

        if (! $this->db->tableExists('cliente_recibo_adjuntos')) {
            $this->forge->addField([
                'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
                'empresa_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
                'recibo_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
                'recibo_medio_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
                'nombre_original' => ['type' => 'VARCHAR', 'constraint' => 255],
                'nombre_almacenado' => ['type' => 'VARCHAR', 'constraint' => 80],
                'ruta_privada' => ['type' => 'VARCHAR', 'constraint' => 255],
                'mime_type' => ['type' => 'VARCHAR', 'constraint' => 100],
                'tamanio' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
                'descripcion' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
                'created_at' => ['type' => 'DATETIME', 'null' => true],
                'created_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addUniqueKey(['empresa_id', 'id'], 'uq_cliente_recibo_adjuntos_empresa_id');
            $this->forge->addKey(['empresa_id', 'recibo_id'], false, false, 'idx_cliente_recibo_adjuntos_recibo');
            $this->forge->addForeignKey('empresa_id', 'empresas', 'id', 'RESTRICT', 'RESTRICT', $this->fk('fk_cliente_recibo_adjuntos_empresa'));
            $this->forge->addForeignKey(['empresa_id', 'recibo_id'], 'cliente_recibos', ['empresa_id', 'id'], 'RESTRICT', 'RESTRICT', $this->fk('fk_cliente_recibo_adjuntos_recibo'));
            $this->forge->createTable('cliente_recibo_adjuntos', true);
        }

        if ($this->db->tableExists('cliente_cc_movimientos')) {
            if (! $this->db->fieldExists('recibo_id', 'cliente_cc_movimientos')) {
                $this->forge->addColumn('cliente_cc_movimientos', [
                    'recibo_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
                ]);
                $this->db->resetDataCache();
            }
            if (! $this->db->fieldExists('recibo_medio_id', 'cliente_cc_movimientos')) {
                $this->forge->addColumn('cliente_cc_movimientos', [
                    'recibo_medio_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
                ]);
                $this->db->resetDataCache();
            }
            $this->createIndexIfMissing('cliente_cc_movimientos', 'idx_cliente_cc_movimientos_recibo', 'empresa_id, recibo_id');
        }

        $this->grantPermissions();
    }

    public function down(): void
    {
        if ($this->db->tableExists('cliente_recibos') && $this->db->table('cliente_recibos')->countAllResults() > 0) {
            throw new \RuntimeException('Ya hay recibos registrados: no se revierte el circuito de cobranzas.');
        }

        $this->forge->dropTable('cliente_recibo_adjuntos', true);
        $this->forge->dropTable('cliente_recibo_imputaciones', true);
        $this->forge->dropTable('cliente_cheques', true);
        $this->forge->dropTable('cliente_recibo_medios', true);
        $this->forge->dropTable('cliente_recibos', true);
        $this->forge->dropTable('cliente_recibos_numeracion', true);

        if ($this->db->tableExists('cliente_cc_movimientos')) {
            if ($this->db->fieldExists('recibo_medio_id', 'cliente_cc_movimientos')) {
                $this->forge->dropColumn('cliente_cc_movimientos', 'recibo_medio_id');
            }
            if ($this->db->fieldExists('recibo_id', 'cliente_cc_movimientos')) {
                $this->forge->dropColumn('cliente_cc_movimientos', 'recibo_id');
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

    private function fk(string $name): string
    {
        return $this->db->DBDriver === 'SQLite3' ? '' : $name;
    }

    private function createIndexIfMissing(string $table, string $name, string $columns): void
    {
        foreach ($this->db->getIndexData($table) as $index) {
            if (($index->name ?? '') === $name) {
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
        $viewers = $administrators;
        $viewPermission = $this->db->table('permisos')->select('id')->where('clave', 'clientes.cc.ver')->get()->getRowArray();
        if ($viewPermission !== null) {
            foreach ($this->db->table('rol_permisos')->select('rol_id')->where('permiso_id', (int) $viewPermission['id'])->get()->getResultArray() as $row) {
                $viewers[] = (int) $row['rol_id'];
            }
        }

        $grants = [
            'clientes.recibos.ver' => array_unique($viewers),
            'clientes.recibos.imprimir' => array_unique($viewers),
            'clientes.recibos.registrar' => array_unique($administrators),
            'clientes.recibos.confirmar' => array_unique($administrators),
            'clientes.recibos.anular' => array_unique($administrators),
            'clientes.recibos.imputar' => array_unique($administrators),
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
