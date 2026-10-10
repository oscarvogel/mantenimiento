<?php

declare(strict_types=1);

namespace App\Infrastructure\Telematic;

use App\Application\Telematic\Port\MuestraStore;
use App\Domain\Telematic\LecturaImposible;
use App\Domain\Telematic\MedidaAdicional;
use App\Domain\Telematic\MuestraEquipo;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Persistencia del histórico de muestras.
 *
 * Tres cosas que acá no son negociables:
 *
 * 1. **`INSERT IGNORE`, no `INSERT`.** La captura repetida no es un error: es
 *    una corrida reintentada. En MySQL `IGNORE` la salta en silencio y
 *    `affectedRows()` queda en 0; un `INSERT` normal revienta con 1062 y
 *    convierte un reintento del cron en un error visible. Esa diferencia es la
 *    razón por la que esta prueba **no puede correr en SQLite**, donde
 *    `INSERT IGNORE` no existe y la clave única siempre lanza excepción.
 *
 * 2. **Sin `SELECT` previo.** Consultar si existe y después insertar tiene una
 *    ventana entre las dos operaciones en la que otra corrida pasa. Dos
 *    procesos concurrentes —o dos peticiones web simultáneas— insertarían la misma
 *    captura. La clave única es la que arbitra, y es infalsificable; un `SELECT`
 *    previo es una opinión.
 *
 * 3. **El conteo se devuelve, no se supone.** Por (1), el que llama necesita
 *    saber si la fila es nueva, y eso sale de `affectedRows()`, no de asumir.
 */
final class CodeIgniterMuestraStore implements MuestraStore
{
    private readonly BaseConnection $mysql;

    public function __construct(?BaseConnection $mysql = null)
    {
        $this->mysql = $mysql ?? Database::connect();
    }

    public function guardar(MuestraEquipo $muestra): int
    {
        if (! $this->hasTable()) {
            return 0;
        }

        $fila = $this->fila($muestra);

        $columnas = array_keys($fila);
        $marcadores = array_fill(0, count($columnas), '?');

        $sql = 'INSERT IGNORE INTO telematia_muestras ('
            . implode(', ', $columnas) . ') VALUES ('
            . implode(', ', $marcadores) . ')';

        // Los binds de `query()` son posicionales: los marcadores nombrados
        // (`:columna`) son del Query Builder, no del SQL crudo.
        $this->mysql->query($sql, array_values($fila));

        // 1 si la captura era nueva, 0 si ya estaba y MySQL la ignoró.
        return (int) $this->mysql->affectedRows();
    }

    /** @return array<string, mixed> */
    private function fila(MuestraEquipo $muestra): array
    {
        $instantanea = $muestra->instantanea();
        $posicion = $instantanea->posicion();
        $registrada = $muestra->registradaEn()->format('Y-m-d H:i:s');

        return [
            'empresa_id' => $muestra->empresaId(),
            'integracion_id' => $muestra->integracionId(),
            'equipo_id' => $muestra->equipoId(),
            'unidad_externa' => $muestra->unidadExterna(),
            'proveedor' => $muestra->proveedor(),
            'observada_en' => $instantanea->observadaEn()->format('Y-m-d H:i:s'),
            'registrada_en' => $registrada,
            // La posición puede venir ausente: (0,0) no es un lugar, es la
            // falta de un fix. El dominio ya la dejó en null.
            'latitud' => $posicion?->latitude(),
            'longitud' => $posicion?->longitude(),
            'velocidad_kmh' => $posicion?->speedKmh(),
            'rumbo' => $posicion?->course(),
            'altitud_m' => $posicion?->altitudeMeters(),
            'satelites' => $posicion?->satellites(),
            // Una métrica que el proveedor no trae queda en null. Nunca se
            // completa con el valor anterior: en una tabla append-only el
            // valor anterior es de otra captura, y repetirlo no es un dato nuevo.
            'kilometraje' => $instantanea->kilometraje(),
            'horas_decimales' => $instantanea->horasDecimales(),
            'motor_encendido' => $this->booleano($instantanea->motorEncendido()),
            'ralenti_activo' => $this->booleano($instantanea->ralentiActivo()),
            'voltaje' => $instantanea->voltaje(),
            'combustible_litros' => $instantanea->combustibleLitros(),
            'sensores_json' => $this->sensores($instantanea->sensoresAdicionales()),
            'anomalias' => $this->anomalias($instantanea->anomalias()),
            'created_at' => $registrada,
        ];
    }

    private function booleano(?bool $valor): ?int
    {
        return $valor === null ? null : ($valor ? 1 : 0);
    }

    /** @param list<MedidaAdicional> $medidas */
    private function sensores(array $medidas): ?string
    {
        if ($medidas === []) {
            return null;
        }

        $payload = [];

        foreach ($medidas as $medida) {
            $payload[] = [
                'etiqueta' => $medida->etiqueta(),
                'valor' => $medida->valor(),
                'unidad' => $medida->unidad(),
                'tipo' => $medida->tipoProveedor(),
            ];
        }

        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** @param list<LecturaImposible> $anomalias */
    private function anomalias(array $anomalias): ?string
    {
        if ($anomalias === []) {
            return null;
        }

        $payload = [];

        foreach ($anomalias as $anomalia) {
            $payload[] = [
                'sensor' => $anomalia->concepto(),
                'valor' => $anomalia->valorLeido(),
                'motivo' => $anomalia->motivo(),
                'firma' => $anomalia->firma(),
            ];
        }

        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function hasTable(): bool
    {
        static $existe = null;

        if ($existe === null) {
            $existe = $this->mysql->tableExists('telematia_muestras');
        }

        return $existe;
    }
}