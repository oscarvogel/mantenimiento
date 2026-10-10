<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Muestras históricas de telemetría, una por captura y por unidad externa.
 *
 * Es la diferencia entre tener una foto y tener un historial. `telematia_
 * ultima_lectura` guarda dónde está el camión ahora; esta tabla guarda dónde
 * estuvo, cuánto combustible tenía, qué horas de motor tenía y si estaba en
 * ralentí, cada 15 minutos.
 *
 * Sin esta tabla no se puede calcular nada de lo que se pidió: el combustible
 * por trayecto, el porcentaje de ralentí y los excesos de velocidad son
 * funciones de una **serie**, y una serie necesita más de un punto.
 *
 * Tres decisiones que conviene no volver a recorrer:
 *
 * 1. **Es append-only.** Cada corrida agrega. No se sobrescribe ni se borra
 *    una muestra previa, porque un cálculo hecho sobre una serie incompleta
 *    tiene que poder rehacerse cuando llega la muestra que faltaba.
 *
 * 2. **La unicidad es por captura, no por equipo.** `(integracion, unidad
 *    externa, observada_en)` impide que dos insertos de la misma corrida
 *    counted como dos muestras, y a la vez permite apilar las capturas cada
 *    15 minutos del mismo camión. La hora del proveedor va en la clave a
 *    propósito: `registrada_en` cambia en cada reintento, y usarlo haría que
 *    un reintento del cron creara muestras duplicadas.
 *
 * 3. **Toda métrica es nula cuando el proveedor no la trae.** Copiar el
 *    valor anterior y llamarlo lectura nueva es la forma más común de
 *    mostrarle a un operador un tanque lleno que en realidad no se midió.
 *
 * No lleva claves foráneas por la misma razón que `integraciones_telemetria`,
 * `equipo_telemetria` y `telematia_ultima_lectura`: el vínculo con el equipo
 * se resuelve por identificador externo y con la empresa como parte del
 * alcance, porque la misma patente puede existir en dos empresas.
 */
final class CreateTelemetrySamples extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'empresa_id' => ['type' => 'INT', 'unsigned' => true],
            'integracion_id' => ['type' => 'BIGINT', 'unsigned' => true],
            'equipo_id' => ['type' => 'INT', 'unsigned' => true],
            'unidad_externa' => ['type' => 'VARCHAR', 'constraint' => 100],
            'proveedor' => ['type' => 'VARCHAR', 'constraint' => 32],
            'observada_en' => ['type' => 'DATETIME'],
            'registrada_en' => ['type' => 'DATETIME', 'null' => true],
            'latitud' => ['type' => 'DECIMAL', 'constraint' => '10,7', 'null' => true],
            'longitud' => ['type' => 'DECIMAL', 'constraint' => '10,7', 'null' => true],
            'velocidad_kmh' => ['type' => 'DECIMAL', 'constraint' => '7,2', 'null' => true],
            'rumbo' => ['type' => 'SMALLINT', 'unsigned' => true, 'null' => true],
            'altitud_m' => ['type' => 'DECIMAL', 'constraint' => '8,2', 'null' => true],
            'satelites' => ['type' => 'SMALLINT', 'unsigned' => true, 'null' => true],
            'kilometraje' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'horas_decimales' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'motor_encendido' => ['type' => 'TINYINT', 'constraint' => 1, 'null' => true],
            'ralenti_activo' => ['type' => 'TINYINT', 'constraint' => 1, 'null' => true],
            'voltaje' => ['type' => 'DECIMAL', 'constraint' => '6,2', 'null' => true],
            'combustible_litros' => ['type' => 'DECIMAL', 'constraint' => '8,2', 'null' => true],
            // Definiciones y valores de todos los sensores, y las lecturas
            // imposibles que el proveedor reportó. Se conservan para poder
            // estudiar un sensor que no es canónico, y para no perder el
            // hecho de que un sensor definido dejó de entregar valor.
            'sensores_json' => ['type' => 'TEXT', 'null' => true],
            'anomalias' => ['type' => 'TEXT', 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(
            ['integracion_id', 'unidad_externa', 'observada_en'],
            'uq_telemetria_muestras_captura',
        );
        // Lectura por equipo en un período: es la consulta que van a hacer el
        // consumo por viaje, el porcentaje de ralentí y el exceso de velocidad.
        $this->forge->addKey(
            ['empresa_id', 'equipo_id', 'observada_en'],
            false,
            false,
            'idx_telemetria_muestras_equipo',
        );
        // La purga de retención borra por fecha, no por equipo.
        $this->forge->addKey(
            ['empresa_id', 'observada_en'],
            false,
            false,
            'idx_telemetria_muestras_retencion',
        );
        $this->forge->createTable('telematia_muestras', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('telematia_muestras', true);
    }
}