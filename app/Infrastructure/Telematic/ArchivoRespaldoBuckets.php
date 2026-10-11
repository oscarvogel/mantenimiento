<?php

declare(strict_types=1);

namespace App\Infrastructure\Telematic;

use App\Application\Telematic\BucketHistorico;
use App\Application\Telematic\Port\RespaldoBuckets;
use RuntimeException;

/** JSONL completo de buckets: conserva ambos extremos y los segundos de ralentí. */
final class ArchivoRespaldoBuckets implements RespaldoBuckets
{
    private const PREFIJO_PERMITIDO = 'writable/';

    public function escribir(string $archivo, array $buckets): int
    {
        $this->exigirRutaPermitida($archivo);
        $directorio = dirname($archivo);
        if (! is_dir($directorio) && ! @mkdir($directorio, 0o770, true) && ! is_dir($directorio)) {
            throw new RuntimeException('No se pudo crear el directorio del respaldo de buckets: ' . $directorio);
        }

        $this->exigirDestinoFisicoPermitido($archivo);

        $manejador = @fopen($archivo, 'wb');
        if ($manejador === false) {
            throw new RuntimeException('No se pudo abrir el respaldo de buckets para escritura: ' . $archivo);
        }

        $escritos = 0;
        try {
            foreach ($buckets as $bucket) {
                if (! $bucket instanceof BucketHistorico) {
                    throw new RuntimeException('Solo se pueden respaldar buckets históricos completos.');
                }
                $linea = json_encode($bucket->fila(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                if (fwrite($manejador, $linea . "\n") !== strlen($linea) + 1) {
                    throw new RuntimeException('No se pudo escribir el respaldo completo de buckets: ' . $archivo);
                }
                $escritos++;
            }
        } finally {
            fclose($manejador);
        }

        return $escritos;
    }

    public function verificar(string $archivo, int $esperados): bool
    {
        $this->exigirRutaPermitida($archivo);
        $this->exigirDestinoFisicoPermitido($archivo);
        if (! is_file($archivo) || ! is_readable($archivo)) {
            return false;
        }

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
                if (! is_array($fila) || ! isset($fila['bucket_inicio_utc'], $fila['bucket_fin_utc'])) {
                    return false;
                }
                $lineas++;
            }
        } finally {
            fclose($manejador);
        }

        return $lineas === $esperados;
    }

    private function exigirRutaPermitida(string $archivo): void
    {
        $normalizada = str_replace('\\', '/', $archivo);
        $partes = explode('/', $normalizada);
        if (! str_starts_with($normalizada, self::PREFIJO_PERMITIDO) || in_array('..', $partes, true)) {
            throw new RuntimeException('El respaldo de buckets solo puede escribirse dentro de writable/.');
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
            throw new RuntimeException('El respaldo de buckets debe quedar físicamente dentro de writable/.');
        }
    }
}
