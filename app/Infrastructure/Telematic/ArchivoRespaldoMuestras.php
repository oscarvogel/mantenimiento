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

        $this->exigirDestinoFisicoPermitido($archivo);

        $manejador = @fopen($archivo, 'wb');

        if ($manejador === false) {
            throw new RuntimeException('No se pudo abrir el archivo de respaldo para escritura: ' . $archivo);
        }

        $escritas = 0;

        try {
            foreach ($muestras as $muestra) {
                $linea = json_encode($this->fila($muestra), JSON_UNESCAPED_UNICODE);

                $contenido = $linea . "\n";
                if ($linea === false || fwrite($manejador, $contenido) !== strlen($contenido)) {
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
        $this->exigirDestinoFisicoPermitido($archivo);

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
                if (trim($linea) === '') {
                    continue;
                }

                try {
                    $fila = json_decode($linea, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    return false;
                }

                if (! is_array($fila) || ! isset($fila['empresa_id'], $fila['equipo_id'], $fila['observada_en'])) {
                    return false;
                }

                $lineas++;
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
        $partes = explode('/', $normalizada);

        if (! str_starts_with($normalizada, self::PREFIJO_PERMITIDO) || in_array('..', $partes, true)) {
            throw new RuntimeException(
                'El respaldo de telemetría sólo puede escribirse dentro de writable/.',
            );
        }
    }

    private function exigirDestinoFisicoPermitido(string $archivo): void
    {
        $raiz = realpath('writable');
        $directorio = realpath(dirname($archivo));
        $destino = $directorio === false ? false : $directorio . DIRECTORY_SEPARATOR . basename($archivo);

        if (
            $raiz === false
            || $directorio === false
            || ($directorio !== $raiz && ! str_starts_with($directorio, $raiz . DIRECTORY_SEPARATOR))
            || is_link($archivo)
            || ($destino !== false && file_exists($destino) && realpath($destino) === false)
        ) {
            throw new RuntimeException('El respaldo de telemetría debe quedar físicamente dentro de writable/.');
        }
    }
}
