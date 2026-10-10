<?php

declare(strict_types=1);

namespace App\Infrastructure\Telematic;

use App\Application\Telematic\Port\RespaldoMuestras;
use App\Domain\Telematic\MuestraEquipo;
use RuntimeException;

/**
 * Respaldo de capturas en JSON Lines, una por línea.
 *
 * JSONL y no JSON: un respaldo de 400.000 filas que se interrumpe a la mitad
 * tiene que seguir siendo legible hasta donde llegó. Un objeto JSON truncado
 * no se puede parsear y no sirve de nada.
 *
 * El archivo **tiene** que vivir dentro de `writable/`. Es la única carpeta
 * que el servidor puede escribir y que no se versiona, y poner un respaldo en
 * otro lado significaría publicarlo junto al código o pelear con permisos en
 * producción.
 */
final class ArchivoRespaldoMuestras implements RespaldoMuestras
{
    private const PREFIJO_PERMITIDO = 'writable/';

    public function escribir(string $archivo, array $muestras): int
    {
        $this->exigirRutaPermitida($archivo);

        $directorio = dirname($archivo);
        if (! is_dir($directorio) && ! @mkdir($directorio, 0o770, true) && ! is_dir($directorio)) {
            throw new RuntimeException('No se pudo crear el directorio del respaldo: ' . $directorio);
        }

        $manejador = @fopen($archivo, 'wb');

        if ($manejador === false) {
            throw new RuntimeException('No se pudo abrir el archivo de respaldo para escritura: ' . $archivo);
        }

        $escritas = 0;

        try {
            foreach ($muestras as $muestra) {
                $linea = json_encode($this->fila($muestra), JSON_UNESCAPED_UNICODE);

                if ($linea === false || fwrite($manejador, $linea . "\n") === false) {
                    throw new RuntimeException('No se pudo escribir el respaldo completo: ' . $archivo);
                }

                $escritas++;
            }
        } finally {
            fclose($manejador);
        }

        return $escritas;
    }

    public function verificar(string $archivo, int $esperadas): bool
    {
        $this->exigirRutaPermitida($archivo);

        if (! is_file($archivo) || ! is_readable($archivo)) {
            return false;
        }

        // Se cuentan líneas no vacías, no el tamaño: un archivo con el tamaño
        // correcto y contenido corrupto tiene que fallar igual.
        $lineas = 0;
        $manejador = fopen($archivo, 'rb');

        if ($manejador === false) {
            return false;
        }

        try {
            while (($linea = fgets($manejador)) !== false) {
                if (trim($linea) !== '') {
                    $lineas++;
                }
            }
        } finally {
            fclose($manejador);
        }

        return $lineas === $esperadas;
    }

    /** @return array<string,mixed> */
    private function fila(MuestraEquipo $muestra): array
    {
        $posicion = $muestra->posicion();

        return [
            'empresa_id' => $muestra->empresaId(),
            'integracion_id' => $muestra->integracionId(),
            'equipo_id' => $muestra->equipoId(),
            'unidad_externa' => $muestra->unidadExterna(),
            'proveedor' => $muestra->proveedor(),
            'observada_en' => $muestra->observadaEn()->format('Y-m-d H:i:s'),
            'registrada_en' => $muestra->registradaEn()->format('Y-m-d H:i:s'),
            'kilometraje' => $muestra->kilometraje(),
            'horas_decimales' => $muestra->horasDecimales(),
            'motor_encendido' => $muestra->motorEncendido(),
            'ralenti_activo' => $muestra->ralentiActivo(),
            'voltaje' => $muestra->voltaje(),
            'combustible_litros' => $muestra->combustibleLitros(),
            'latitud' => $posicion?->latitude(),
            'longitud' => $posicion?->longitude(),
            'velocidad_kmh' => $posicion?->speedKmh(),
        ];
    }

    private function exigirRutaPermitida(string $archivo): void
    {
        $normalizada = str_replace('\\', '/', $archivo);

        if (! str_starts_with($normalizada, self::PREFIJO_PERMITIDO)) {
            throw new RuntimeException(
                'El respaldo de telemetría sólo puede escribirse dentro de writable/.',
            );
        }
    }
}