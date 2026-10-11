<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Umbrales de cálculo de consumo por empresa.
 *
 * Los valores por defecto del código son razonables pero **no están medidos**:
 * calibrarlos exige días de telemetría real, que sólo se acumulan cuando esto
 * está en producción. Por eso viven en una tabla y no en constantes.
 *
 * Todas las columnas son anulables a propósito. Null significa **«sin
 * configurar»**, que es una tercera cosa distinta de cualquier número: un
 * `delta_minimo_litros = 0` no significa lo mismo que `NULL`. El primero
 * desactiva el descarte por ruido y cuenta cada fluctuación del sensor como
 * consumo; el segundo deja el valor por defecto y marca el reporte como no
 * calibrado.
 *
 * Una fila por empresa, con `empresa_id` único. Es la tabla de la que salen
 * los valores que el cálculo aplica, así que el scoping no es opcional: los
 * umbrales de una empresa nunca pueden aplicarle a otra.
 *
 * No lleva claves foráneas por la misma razón que el resto de la telemetría: la
 * empresa se alcanza por identificador, y el borrado de una empresa se resuelve
 * en la capa de aplicación.
 */
final class CreateTelemetryConsumptionSettings extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],

            'empresa_id' => ['type' => 'INT', 'unsigned' => true],

            // --- atribución de litros ---
            'delta_minimo_litros' => ['type' => 'DECIMAL', 'constraint' => '8,2', 'null' => true],
            'umbral_carga_litros' => ['type' => 'DECIMAL', 'constraint' => '8,2', 'null' => true],
            'hueco_maximo_atribucion_minutos' => ['type' => 'INT', 'unsigned' => true, 'null' => true],

            // --- segmentación de viajes ---
            'km_minimos_viaje' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'minutos_minimos_viaje' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'intervalos_parado_para_cerrar' => ['type' => 'INT', 'unsigned' => true, 'null' => true],

            // --- cobertura ---
            'cobertura_minima' => ['type' => 'DECIMAL', 'constraint' => '5,4', 'null' => true],
            'hueco_maximo_cobertura_minutos' => ['type' => 'INT', 'unsigned' => true, 'null' => true],

            // --- plausibilidad de las horas de motor ---
            'velocidad_minima_plausible_kmh' => ['type' => 'DECIMAL', 'constraint' => '6,2', 'null' => true],
            'velocidad_maxima_plausible_kmh' => ['type' => 'DECIMAL', 'constraint' => '6,2', 'null' => true],

            'actualizado_por' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);

        $this->forge->addPrimaryKey('id');

        // Una sola fila por empresa: los umbrales no se versionan por equipo
        // ni por sucursal en esta versión, y dos filas sería ambigüedad.
        $this->forge->addUniqueKey('empresa_id', 'uq_telemetria_consumo_config_empresa');

        $this->forge->createTable('telemetria_consumo_config', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('telemetria_consumo_config', true);
    }
}