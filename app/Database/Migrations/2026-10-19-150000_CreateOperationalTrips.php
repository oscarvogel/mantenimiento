<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Viajes operativos planificados por la empresa.
 *
 * No reutiliza `viajes` porque esa tabla conserva el historial legacy importado,
 * con sus claves naturales y columnas de origen. El viaje operativo tiene su
 * propio ciclo (planificado, en curso, finalizado, cancelado) y su propia
 * numeración correlativa por empresa.
 */
final class CreateOperationalTrips extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'empresa_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'sucursal_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'numero' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'estado' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'PLANIFICADO'],
            'fecha_salida_planificada' => ['type' => 'DATETIME'],
            'fecha_regreso_planificada' => ['type' => 'DATETIME'],
            'fecha_salida_real' => ['type' => 'DATETIME', 'null' => true],
            'fecha_regreso_real' => ['type' => 'DATETIME', 'null' => true],
            'km_salida' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'km_regreso' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'observaciones' => ['type' => 'VARCHAR', 'constraint' => 1000, 'null' => true],
            'motivo_cancelacion' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
            'created_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'updated_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['empresa_id', 'numero'], 'uq_viajes_operativos_numero');
        $this->forge->addUniqueKey(['empresa_id', 'id'], 'uq_viajes_operativos_empresa_id');
        $this->forge->addKey(
            ['empresa_id', 'estado', 'fecha_salida_planificada', 'fecha_regreso_planificada'],
            false,
            false,
            'idx_viajes_operativos_agenda',
        );
        $this->forge->addKey(['empresa_id', 'sucursal_id', 'fecha_salida_planificada'], false, false, 'idx_viajes_operativos_sucursal');
        $this->forge->addForeignKey('empresa_id', 'empresas', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey('sucursal_id', 'sucursales', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->addForeignKey('created_by', 'usuarios', 'id', 'SET NULL', 'RESTRICT');
        $this->forge->addForeignKey('updated_by', 'usuarios', 'id', 'SET NULL', 'RESTRICT');
        $this->forge->createTable('viajes_operativos', true);

        $this->forge->addField([
            'empresa_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'ultimo_numero' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'default' => 0],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('empresa_id', true);
        $this->forge->addForeignKey('empresa_id', 'empresas', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->createTable('viajes_operativos_numeracion', true);

        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'viaje_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'empresa_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'sucursal_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'rol' => ['type' => 'VARCHAR', 'constraint' => 20],
            'equipo_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'empleado_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'desde' => ['type' => 'DATETIME'],
            'hasta' => ['type' => 'DATETIME', 'null' => true],
            'motivo' => ['type' => 'VARCHAR', 'constraint' => 300, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['empresa_id', 'id'], 'uq_viaje_operativo_asignaciones_empresa_id');
        $this->forge->addKey('viaje_id', false, false, 'idx_viaje_operativo_asignaciones_viaje');
        $this->forge->addKey(['empresa_id', 'equipo_id', 'desde'], false, false, 'idx_viaje_operativo_asignaciones_equipo');
        $this->forge->addKey(['empresa_id', 'empleado_id', 'desde'], false, false, 'idx_viaje_operativo_asignaciones_empleado');
        $this->forge->addForeignKey('viaje_id', 'viajes_operativos', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('empresa_id', 'empresas', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey('equipo_id', 'equipos', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->addForeignKey('empleado_id', 'empleados', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->createTable('viaje_operativo_asignaciones', true);

        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'viaje_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'empresa_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'sucursal_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'orden' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'tipo' => ['type' => 'VARCHAR', 'constraint' => 20],
            'cliente_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'domicilio_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'ubicacion' => ['type' => 'VARCHAR', 'constraint' => 200],
            'fecha_estimada' => ['type' => 'DATETIME', 'null' => true],
            'fecha_real' => ['type' => 'DATETIME', 'null' => true],
            'descripcion_carga' => ['type' => 'VARCHAR', 'constraint' => 300, 'null' => true],
            'cantidad' => ['type' => 'DECIMAL', 'constraint' => '14,2', 'null' => true],
            'unidad_carga_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'observaciones' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['empresa_id', 'id'], 'uq_viaje_operativo_paradas_empresa_id');
        $this->forge->addUniqueKey(['viaje_id', 'orden'], 'uq_viaje_operativo_paradas_orden');
        $this->forge->addKey(['empresa_id', 'sucursal_id'], false, false, 'idx_viaje_operativo_paradas_sucursal');
        $this->forge->addKey(['empresa_id', 'cliente_id'], false, false, 'idx_viaje_operativo_paradas_cliente');
        $this->forge->addForeignKey('viaje_id', 'viajes_operativos', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('empresa_id', 'empresas', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey('cliente_id', 'clientes', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->addForeignKey('domicilio_id', 'viajes_domicilios_clientes', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->addForeignKey('unidad_carga_id', 'viajes_unidades_carga', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->createTable('viaje_operativo_paradas', true);

        $this->permissions();
    }

    public function down(): void
    {
        foreach ([
            'viaje_operativo_paradas',
            'viaje_operativo_asignaciones',
            'viajes_operativos_numeracion',
            'viajes_operativos',
        ] as $table) {
            $this->forge->dropTable($table, true);
        }

        $rows = $this->db->table('permisos')->select('id')->where('clave', 'viajes.operar')->get()->getResultArray();
        $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        if ($ids !== []) {
            $this->db->table('rol_permisos')->whereIn('permiso_id', $ids)->delete();
            $this->db->table('permisos')->whereIn('id', $ids)->delete();
        }
    }

    private function permissions(): void
    {
        $now = date('Y-m-d H:i:s');
        if (! $this->db->table('permisos')->where('clave', 'viajes.operar')->countAllResults()) {
            $this->db->table('permisos')->insert([
                'clave' => 'viajes.operar',
                'descripcion' => 'Crear, iniciar y cancelar viajes operativos',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $permission = $this->db->table('permisos')->select('id')->where('clave', 'viajes.operar')->get()->getRowArray();
        $permissionId = (int) ($permission['id'] ?? 0);
        if ($permissionId === 0) {
            return;
        }

        $roles = $this->db->table('roles')->select('id')
            ->whereIn('nombre', ['Administrador', 'Responsable de mantenimiento'])
            ->get()->getResultArray();
        foreach ($roles as $role) {
            $relation = ['rol_id' => (int) $role['id'], 'permiso_id' => $permissionId];
            if (! $this->db->table('rol_permisos')->where($relation)->countAllResults()) {
                $this->db->table('rol_permisos')->insert($relation + ['created_at' => $now]);
            }
        }
    }
}
