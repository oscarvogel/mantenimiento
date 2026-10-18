<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Agregado operativo de Viajes: encabezado y sus tablas hijas.
 *
 * El destino no inventa equivalencias: cada fila conserva los identificadores del
 * legacy (`cuit_empresa`, `dominio`, `numero_viaje`, `item`) para poder auditar y
 * volver al origen. Los enlaces a `equipos` y `empleados` son opcionales porque esos
 * maestros todavia no estan cargados; mientras tanto el dominio y el legajo del
 * chofer quedan guardados tal cual llegaron del dump.
 *
 * El aislamiento por empresa y sucursal se repite en cada tabla hija. Un filtro de
 * listados no tiene que confiar en el encabezado para no mostrar una fila de otra
 * sucursal.
 *
 * Tablas y renombres respecto del legacy:
 *   t05_viajes                            -> viajes
 *   t06_origenes_destinos_por_viaje       -> viaje_tramos
 *   t07_cargas_combustible_por_viaje      -> viaje_combustible
 *   t08_gastos_por_viaje                  -> viaje_gastos
 *   t11_cuenta_corriente_x_viaje          -> viaje_cuenta_corriente
 *   t12_cuenta_corriente_origenes_destinos_por_viaje -> viaje_cuenta_corriente_tramos
 *   t13_otros_haberes_por_viaje            -> viaje_otros_haberes
 *
 * `t05.observaciones_1..3` conserva el nombre. `t06` los llama `observaciones1..4`
 * sin guion y aqui quedan `observaciones_1..4`. El resto de las columnas mantiene el
 * nombre del origen, salvo `t06.codigo_cliente`, que se guarda como
 * `codigo_cliente_origen` para dejar claro que es el codigo del legacy y no el del
 * padron canonico.
 */
final class CreateTripHistoryAggregate extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'empresa_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'sucursal_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'origen' => ['type' => 'VARCHAR', 'constraint' => 80],
            'cuit_origen' => ['type' => 'CHAR', 'constraint' => 13],
            'dominio_origen' => ['type' => 'CHAR', 'constraint' => 10],
            'numero_viaje' => ['type' => 'INT', 'unsigned' => true],
            'fecha_salida' => ['type' => 'DATE'],
            'fecha_regreso' => ['type' => 'DATE', 'null' => true],
            'legajo_chofer_origen' => ['type' => 'CHAR', 'constraint' => 13, 'null' => true],
            'empleado_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'equipo_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'observaciones_1' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'observaciones_2' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'observaciones_3' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'km_salida' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'km_regreso' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'contabilizado' => ['type' => 'TINYINT', 'constraint' => 1, 'null' => true],
            'fecha_contabilizacion' => ['type' => 'DATE', 'null' => true],
            'porcentaje_chofer' => ['type' => 'DECIMAL', 'constraint' => '10,2', 'null' => true],
            'marcar' => ['type' => 'TINYINT', 'constraint' => 1, 'null' => true],
            'importacion_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'activo' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
            'created_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'updated_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['empresa_id', 'id'], 'uq_viajes_empresa_id');
        // Clave de origen: es la PK real del legacy (cuit_empresa, dominio, numero_viaje).
        // Reimportar el mismo archivo no debe duplicar viajes.
        $this->forge->addUniqueKey(
            ['empresa_id', 'origen', 'cuit_origen', 'dominio_origen', 'numero_viaje'],
            'uq_viajes_origen_natural'
        );
        $this->forge->addKey(['empresa_id', 'sucursal_id', 'fecha_salida']);
        $this->forge->addKey(['empresa_id', 'fecha_salida']);
        $this->forge->addKey(['empresa_id', 'dominio_origen']);
        $this->forge->addKey('importacion_id');
        $this->forge->addForeignKey('empresa_id', 'empresas', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey('sucursal_id', 'sucursales', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->addForeignKey('importacion_id', 'importaciones', 'id', 'SET NULL', 'RESTRICT');
        $this->forge->addForeignKey('created_by', 'usuarios', 'id', 'SET NULL', 'RESTRICT');
        $this->forge->addForeignKey('updated_by', 'usuarios', 'id', 'SET NULL', 'RESTRICT');
        $this->forge->createTable('viajes', true);

        $this->tablaHija('viaje_tramos', [
            'item' => ['type' => 'INT', 'unsigned' => true],
            'codigo_origen_destino' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'cantidad_kilometros' => ['type' => 'DECIMAL', 'constraint' => '14,2', 'null' => true],
            'toneladas' => ['type' => 'DECIMAL', 'constraint' => '14,2', 'null' => true],
            'precio' => ['type' => 'DECIMAL', 'constraint' => '18,4', 'null' => true],
            'importe' => ['type' => 'DECIMAL', 'constraint' => '18,4', 'null' => true],
            'importe_inicial' => ['type' => 'DECIMAL', 'constraint' => '18,4', 'null' => true],
            'porcentaje_facturacion' => ['type' => 'DECIMAL', 'constraint' => '7,2', 'null' => true],
            'descripcion_origen' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'descripcion_destino' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'observaciones_1' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'observaciones_2' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'observaciones_3' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'observaciones_4' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'remito' => ['type' => 'VARCHAR', 'constraint' => 60, 'null' => true],
            'codigo_alternativo' => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'cobrado' => ['type' => 'TINYINT', 'constraint' => 1, 'null' => true],
            'codigo_cliente_origen' => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'cliente_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'fecha_salida' => ['type' => 'DATE', 'null' => true],
            'fecha_llegada' => ['type' => 'DATE', 'null' => true],
        ], ['item'], 'codigo_cliente_origen');

        $this->tablaHija('viaje_combustible', [
            'item' => ['type' => 'INT', 'unsigned' => true],
            'codigo_concepto' => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'litros' => ['type' => 'DECIMAL', 'constraint' => '14,2', 'null' => true],
            'importe' => ['type' => 'DECIMAL', 'constraint' => '18,4', 'null' => true],
            'observaciones' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'fecha' => ['type' => 'DATE', 'null' => true],
            'kilometraje_actual' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
        ], ['item']);

        $this->tablaHija('viaje_gastos', [
            'item' => ['type' => 'INT', 'unsigned' => true],
            'codigo_concepto' => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'importe' => ['type' => 'DECIMAL', 'constraint' => '18,4', 'null' => true],
            'observaciones' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'fecha' => ['type' => 'DATE', 'null' => true],
        ], ['item']);

        // El legacy guarda el importe en moneda original y, por separado, una
        // conversion a moneda local y la cotizacion. Se preservan los tres tal
        // cual: no se calcula ningun saldo ni total derivado.
        $this->tablaHija('viaje_cuenta_corriente', [
            'item' => ['type' => 'INT', 'unsigned' => true],
            'codigo_concepto' => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'debitos' => ['type' => 'DECIMAL', 'constraint' => '18,4', 'null' => true],
            'creditos' => ['type' => 'DECIMAL', 'constraint' => '18,4', 'null' => true],
            'debitos_moneda' => ['type' => 'DECIMAL', 'constraint' => '18,4', 'null' => true],
            'creditos_moneda' => ['type' => 'DECIMAL', 'constraint' => '18,4', 'null' => true],
            'importe_moneda' => ['type' => 'DECIMAL', 'constraint' => '18,4', 'null' => true],
            'cotizacion' => ['type' => 'DECIMAL', 'constraint' => '18,6', 'null' => true],
            'id_moneda' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'observaciones' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'fecha' => ['type' => 'DATE', 'null' => true],
        ], ['item']);

        $this->tablaHija('viaje_cuenta_corriente_tramos', [
            'item' => ['type' => 'INT', 'unsigned' => true],
            'sub_item' => ['type' => 'INT', 'unsigned' => true],
            'tipo_comprobante' => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'punto_venta' => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'numero_comprobante' => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true],
            'importe' => ['type' => 'DECIMAL', 'constraint' => '18,4', 'null' => true],
            'fecha' => ['type' => 'DATE', 'null' => true],
        ], ['item', 'sub_item']);

        $this->tablaHija('viaje_otros_haberes', [
            'item' => ['type' => 'INT', 'unsigned' => true],
            'codigo_concepto' => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'importe' => ['type' => 'DECIMAL', 'constraint' => '18,4', 'null' => true],
            'observaciones' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'fecha' => ['type' => 'DATE', 'null' => true],
        ], ['item']);
    }

    public function down(): void
    {
        foreach ([
            'viaje_otros_haberes',
            'viaje_cuenta_corriente_tramos',
            'viaje_cuenta_corriente',
            'viaje_gastos',
            'viaje_combustible',
            'viaje_tramos',
            'viajes',
        ] as $tabla) {
            $this->forge->dropTable($tabla, true);
        }
    }

    /**
     * Tabla hija del viaje. Repite empresa y sucursal para que un listado no dependa
     * de recorrer el encabezado, y conserva el `item` del legacy en la clave de origen.
     *
     * @param array<string, array<string, mixed>> $campos
     * @param array<int,string> $claveOrigen columnas que, junto a `viaje_id`, dan la unicidad
     */
    private function tablaHija(string $tabla, array $campos, array $claveOrigen, ?string $columnaResolucion = null): void
    {
        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'viaje_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'empresa_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'sucursal_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            ...$campos,
            'importacion_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['empresa_id', 'id'], 'uq_' . $tabla . '_empresa_id');
        $this->forge->addUniqueKey(['viaje_id', ...$claveOrigen], 'uq_' . $tabla . '_viaje_item');
        $this->forge->addKey(['empresa_id', 'sucursal_id']);
        // Solo las tablas que tienen `fecha` en el legacy. `t06` no la tiene: sus
        // fechas son `fecha_salida` y `fecha_llegada`.
        if (array_key_exists('fecha', $campos)) {
            $this->forge->addKey(['empresa_id', 'fecha'], false, false, 'idx_' . $tabla . '_empresa_fecha');
        } elseif (array_key_exists('fecha_salida', $campos)) {
            $this->forge->addKey(['empresa_id', 'fecha_salida'], false, false, 'idx_' . $tabla . '_empresa_salida');
        }
        $this->forge->addKey('importacion_id');
        if ($columnaResolucion !== null) {
            $this->forge->addKey(['empresa_id', $columnaResolucion]);
        }
        $this->forge->addForeignKey('empresa_id', 'empresas', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey('viaje_id', 'viajes', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('importacion_id', 'importaciones', 'id', 'SET NULL', 'RESTRICT');
        $this->forge->createTable($tabla, true);
    }
}