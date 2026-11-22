<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Corrige la hora observada de Autotrac ya guardada.
 *
 * ATIC informa en UTC sin zona y se guardaba como hora de Argentina: 3 h hacia
 * adelante. Una muestra con la hora observada más de 60 minutos posterior a la
 * hora en que la registramos es imposible, así que ese es el criterio: corrige
 * solo esas filas, y lo que entre bien después del arreglo no se toca. Correr
 * la migración dos veces no corre nada dos veces.
 *
 * Si al restar 3 h la muestra coincide con otra ya guardada de la misma
 * unidad (la misma captura leída antes y después del arreglo), se borra la
 * vieja en lugar de violar la clave única.
 *
 * Es portable (SQLite y MySQL): la cuenta se hace en PHP.
 */
final class FixAutotracObservedTimezone extends Migration
{
    private const DESFASE_SEGUNDOS = 3 * 3600;
    private const MARGEN_SEGUNDOS = 3600;
    private const LOTE = 500;

    public function up(): void
    {
        foreach (['telematia_muestras', 'telematia_ultima_lectura'] as $tabla) {
            if ($this->db->tableExists($tabla)) {
                $this->corregir($tabla);
            }
        }
    }

    public function down(): void
    {
        // Corrección de datos sin vuelta atrás segura: una vez corregida, una fila
        // no se distingue de una que entró bien. El backup previo al deploy es el
        // camino para revertir.
    }

    private function corregir(string $tabla): void
    {
        $filas = $this->db->table($tabla)
            ->select('id, integracion_id, unidad_externa, observada_en, registrada_en')
            ->where('proveedor', 'autotrac')
            ->where('registrada_en IS NOT NULL', null, false)
            ->get()
            ->getResultArray();

        // Primero se decide la hora final de cada fila. Solo hay duplicado cuando
        // dos filas terminan en la misma hora final: dos muestras reales
        // separadas 3 h, ambas corridas, no chocan, porque se mueven las dos.
        $finales = [];
        foreach ($filas as $fila) {
            $observada = strtotime((string) $fila['observada_en']);
            $registrada = strtotime((string) $fila['registrada_en']);
            $corrida = $observada !== false && $registrada !== false && $observada - $registrada > self::MARGEN_SEGUNDOS;
            $final = $corrida ? date('Y-m-d H:i:s', $observada - self::DESFASE_SEGUNDOS) : (string) $fila['observada_en'];
            $finales[$fila['integracion_id'] . '|' . $fila['unidad_externa'] . '|' . $final][] = [
                'id' => (int) $fila['id'],
                'corrida' => $corrida,
                'original' => (string) $fila['observada_en'],
                'final' => $final,
            ];
        }

        $actualizar = [];
        $borrar = [];
        foreach ($finales as $grupo) {
            // Gana la fila que ya estaba bien; si no hay, la primera.
            usort($grupo, static fn (array $a, array $b): int => [$a['corrida'], $a['id']] <=> [$b['corrida'], $b['id']]);
            $queda = array_shift($grupo);
            foreach ($grupo as $sobrante) {
                $borrar[] = $sobrante['id'];
            }
            if ($queda['corrida']) {
                $actualizar[] = $queda;
            }
        }

        foreach (array_chunk($borrar, self::LOTE) as $lote) {
            $this->db->table($tabla)->whereIn('id', $lote)->delete();
        }

        // De a una y de la más antigua a la más nueva: restar 3 h mueve cada fila
        // a un valor anterior, que ocupa una fila más antigua que ya se movió.
        // Un UPDATE masivo podría chocar con la clave única a mitad de camino.
        usort($actualizar, static fn (array $a, array $b): int => [$a['original'], $a['id']] <=> [$b['original'], $b['id']]);
        $this->db->transStart();
        foreach ($actualizar as $fila) {
            $this->db->table($tabla)->where('id', $fila['id'])->update(['observada_en' => $fila['final']]);
        }
        $this->db->transComplete();

        log_message('notice', 'FixAutotracObservedTimezone: {tabla} corregidas={n} duplicadas_borradas={d}', [
            'tabla' => $tabla,
            'n' => count($actualizar),
            'd' => count($borrar),
        ]);
    }
}
