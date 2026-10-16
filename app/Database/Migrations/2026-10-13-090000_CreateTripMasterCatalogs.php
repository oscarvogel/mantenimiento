<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class CreateTripMasterCatalogs extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'codigo' => ['type' => 'CHAR', 'constraint' => 3],
            'nombre' => ['type' => 'VARCHAR', 'constraint' => 100],
            'activo' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('codigo', 'uq_viajes_monedas_codigo');
        $this->forge->createTable('viajes_monedas', true);
        $this->db->table('viajes_monedas')->insertBatch([
            ['codigo' => 'ARS', 'nombre' => 'Peso argentino'],
            ['codigo' => 'BOB', 'nombre' => 'Boliviano'],
            ['codigo' => 'BRL', 'nombre' => 'Real brasileño'],
            ['codigo' => 'CLP', 'nombre' => 'Peso chileno'],
            ['codigo' => 'COP', 'nombre' => 'Peso colombiano'],
            ['codigo' => 'EUR', 'nombre' => 'Euro'],
            ['codigo' => 'GYD', 'nombre' => 'Dólar guyanés'],
            ['codigo' => 'PEN', 'nombre' => 'Sol peruano'],
            ['codigo' => 'PYG', 'nombre' => 'Guaraní paraguayo'],
            ['codigo' => 'SRD', 'nombre' => 'Dólar surinamés'],
            ['codigo' => 'USD', 'nombre' => 'Dólar estadounidense'],
            ['codigo' => 'UYU', 'nombre' => 'Peso uruguayo'],
            ['codigo' => 'VES', 'nombre' => 'Bolívar venezolano'],
        ]);

        $this->forge->addField([
            'id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'codigo_iso' => ['type' => 'CHAR', 'constraint' => 2],
            'nombre' => ['type' => 'VARCHAR', 'constraint' => 100],
            'activo' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('codigo_iso', 'uq_viajes_paises_codigo_iso');
        $this->forge->createTable('viajes_paises', true);
        $this->db->table('viajes_paises')->insertBatch([
            ['codigo_iso' => 'AR', 'nombre' => 'Argentina'],
            ['codigo_iso' => 'BO', 'nombre' => 'Bolivia'],
            ['codigo_iso' => 'BR', 'nombre' => 'Brasil'],
            ['codigo_iso' => 'CL', 'nombre' => 'Chile'],
            ['codigo_iso' => 'CO', 'nombre' => 'Colombia'],
            ['codigo_iso' => 'EC', 'nombre' => 'Ecuador'],
            ['codigo_iso' => 'GF', 'nombre' => 'Guayana Francesa'],
            ['codigo_iso' => 'GY', 'nombre' => 'Guyana'],
            ['codigo_iso' => 'PE', 'nombre' => 'Perú'],
            ['codigo_iso' => 'PY', 'nombre' => 'Paraguay'],
            ['codigo_iso' => 'SR', 'nombre' => 'Surinam'],
            ['codigo_iso' => 'UY', 'nombre' => 'Uruguay'],
            ['codigo_iso' => 'VE', 'nombre' => 'Venezuela'],
        ]);

        $this->createTenantCatalog('viajes_clientes', [
            'nombre' => ['type' => 'VARCHAR', 'constraint' => 160],
            'identificacion_fiscal' => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'telefono' => ['type' => 'VARCHAR', 'constraint' => 60, 'null' => true],
            'email' => ['type' => 'VARCHAR', 'constraint' => 160, 'null' => true],
        ], ['nombre']);

        $this->forge->addField([
            'id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'empresa_id' => ['type' => 'INT', 'unsigned' => true],
            'cliente_id' => ['type' => 'INT', 'unsigned' => true],
            'alias' => ['type' => 'VARCHAR', 'constraint' => 100],
            'calle' => ['type' => 'VARCHAR', 'constraint' => 180],
            'localidad' => ['type' => 'VARCHAR', 'constraint' => 120],
            'provincia' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'pais_id' => ['type' => 'INT', 'unsigned' => true],
            'codigo_postal' => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true],
            'activo' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
            'created_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'updated_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['empresa_id', 'id'], 'uq_viajes_domicilios_empresa_id');
        $this->forge->addKey(['empresa_id', 'cliente_id', 'activo']);
        $this->forge->addForeignKey(['empresa_id'], 'empresas', ['id'], 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey(['empresa_id', 'cliente_id'], 'viajes_clientes', ['empresa_id', 'id'], 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey('pais_id', 'viajes_paises', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->addForeignKey('created_by', 'usuarios', 'id', 'SET NULL', 'RESTRICT');
        $this->forge->addForeignKey('updated_by', 'usuarios', 'id', 'SET NULL', 'RESTRICT');
        $this->forge->createTable('viajes_domicilios_clientes', true);

        foreach ([
            'viajes_tipos_carga' => ['nombre' => ['type' => 'VARCHAR', 'constraint' => 120], 'descripcion' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true]],
            'viajes_unidades_carga' => ['nombre' => ['type' => 'VARCHAR', 'constraint' => 100], 'simbolo' => ['type' => 'VARCHAR', 'constraint' => 20]],
            'viajes_categorias_gasto' => ['nombre' => ['type' => 'VARCHAR', 'constraint' => 120], 'descripcion' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true]],
            'viajes_medios_pago' => ['nombre' => ['type' => 'VARCHAR', 'constraint' => 100], 'descripcion' => ['type' => 'VARCHAR', 'constraint' => 300, 'null' => true]],
            'viajes_tipos_documento' => ['nombre' => ['type' => 'VARCHAR', 'constraint' => 140], 'codigo' => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true], 'descripcion' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true]],
        ] as $table => $fields) {
            $this->createTenantCatalog($table, $fields, ['nombre']);
        }

        $this->forge->addField([
            'id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'empresa_id' => ['type' => 'INT', 'unsigned' => true],
            'empleado_id' => ['type' => 'INT', 'unsigned' => true],
            'porcentaje' => ['type' => 'DECIMAL', 'constraint' => '5,2'],
            'vigente_desde' => ['type' => 'DATE'],
            'vigente_hasta' => ['type' => 'DATE', 'null' => true],
            'activo' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
            'created_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'updated_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['empresa_id', 'id'], 'uq_viajes_liquidacion_empresa_id');
        $this->forge->addUniqueKey(['empresa_id', 'empleado_id', 'vigente_desde'], 'uq_viajes_liquidacion_inicio');
        $this->forge->addKey(['empresa_id', 'empleado_id', 'activo', 'vigente_desde']);
        $this->forge->addForeignKey('empresa_id', 'empresas', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey('empleado_id', 'empleados', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->createTable('viajes_porcentajes_liquidacion_chofer', true);

        $this->permissions();
    }

    public function down(): void
    {
        foreach ([
            'viajes_domicilios_clientes',
            'viajes_porcentajes_liquidacion_chofer',
            'viajes_tipos_documento',
            'viajes_medios_pago',
            'viajes_categorias_gasto',
            'viajes_unidades_carga',
            'viajes_tipos_carga',
            'viajes_clientes',
            'viajes_paises',
            'viajes_monedas',
        ] as $table) {
            $this->forge->dropTable($table, true);
        }
        $ids = $this->db->table('permisos')->select('id')->whereIn('clave', ['viajes.maestros.ver', 'viajes.maestros.editar'])->get()->getResultArray();
        $permissionIds = array_map(static fn (array $row): int => (int) $row['id'], $ids);
        if ($permissionIds !== []) {
            $this->db->table('rol_permisos')->whereIn('permiso_id', $permissionIds)->delete();
            $this->db->table('permisos')->whereIn('id', $permissionIds)->delete();
        }
    }

    /** @param array<string,array<string,mixed>> $fields @param list<string> $searchColumns */
    private function createTenantCatalog(string $table, array $fields, array $searchColumns): void
    {
        $this->forge->addField([
            'id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'empresa_id' => ['type' => 'INT', 'unsigned' => true],
            ...$fields,
            'activo' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
            'created_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'updated_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['empresa_id', 'id'], 'uq_' . $table . '_empresa_id');
        $this->forge->addKey(['empresa_id', 'activo']);
        $this->forge->addKey(['empresa_id', ...$searchColumns]);
        $this->forge->addForeignKey('empresa_id', 'empresas', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey('created_by', 'usuarios', 'id', 'SET NULL', 'RESTRICT');
        $this->forge->addForeignKey('updated_by', 'usuarios', 'id', 'SET NULL', 'RESTRICT');
        $this->forge->createTable($table, true);
    }

    private function permissions(): void
    {
        $now = date('Y-m-d H:i:s');
        $permissions = [
            'viajes.maestros.ver' => 'Consultar los maestros del módulo de Viajes',
            'viajes.maestros.editar' => 'Crear y actualizar los maestros del módulo de Viajes',
        ];
        foreach ($permissions as $key => $description) {
            if (! $this->db->table('permisos')->where('clave', $key)->countAllResults()) {
                $this->db->table('permisos')->insert(['clave' => $key, 'descripcion' => $description, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
        $roles = $this->db->table('roles')->select('id')->whereIn('nombre', ['Administrador', 'Responsable de mantenimiento'])->get()->getResultArray();
        $permissionRows = $this->db->table('permisos')->select('id, clave')->whereIn('clave', array_keys($permissions))->get()->getResultArray();
        foreach ($roles as $role) {
            foreach ($permissionRows as $permission) {
                $relation = ['rol_id' => (int) $role['id'], 'permiso_id' => (int) $permission['id']];
                if (! $this->db->table('rol_permisos')->where($relation)->countAllResults()) {
                    $this->db->table('rol_permisos')->insert($relation + ['created_at' => $now]);
                }
            }
        }
    }
}
