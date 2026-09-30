<?php

declare(strict_types=1);

namespace Tests\Support\ReadingControl;

/**
 * Utilidad para pruebas de contrato sobre código fuente PHP.
 *
 * Los contratos verifican que el hotfix de solo consulta no reintroduzca
 * dependencias de WhatsApp ni de reclamo. Esos términos aparecen legitimamente
 * en los docblocks que EXPLICAN por qué la dependencia se eliminó, así que
 * buscar el texto crudo daba falsos positivos.
 *
 * Se eliminan únicamente los comentarios: los literales de cadena se conservan
 * porque sí son dependencias reales (por ejemplo
 * `base_url('mantenimiento/lecturas/control/reclamar')`).
 */
final class PhpSource
{
    public static function code(string $source): string
    {
        $output = [];
        $length = strlen($source);
        $index = 0;
        $inString = false;
        $quote = '';

        while ($index < $length) {
            $char = $source[$index];
            $next = $index + 1 < $length ? $source[$index + 1] : '';

            if ($inString) {
                $output[] = $char;
                if ($char === '\\') {
                    $output[] = $next;
                    $index += 2;

                    continue;
                }
                if ($char === $quote) {
                    $inString = false;
                }
                $index++;

                continue;
            }

            // Apertura de literal de cadena.
            if ($char === "'" || $char === '"') {
                $inString = true;
                $quote = $char;
                $output[] = $char;
                $index++;

                continue;
            }

            // Comentario de bloque.
            if ($char === '/' && $next === '*') {
                $end = strpos($source, '*/', $index + 2);
                $index = $end === false ? $length : $end + 2;

                continue;
            }

            // Comentario de linea. El operador de division se distingue por el
            // contexto: aqui se prioriza no partir expresiones validas.
            if ($char === '/' && $next === '/') {
                while ($index < $length && $source[$index] !== "\n") {
                    $index++;
                }

                continue;
            }

            if ($char === '#') {
                while ($index < $length && $source[$index] !== "\n") {
                    $index++;
                }

                continue;
            }

            $output[] = $char;
            $index++;
        }

        return implode('', $output);
    }

    /**
     * Lee un archivo de la aplicación y devuelve solo su código.
     */
    public static function codeOf(string $absolutePath): string
    {
        $source = file_get_contents($absolutePath);
        self::assertReadable($absolutePath, $source);

        return self::code($source);
    }

    private static function assertReadable(string $path, mixed $source): void
    {
        if (! is_string($source)) {
            throw new \RuntimeException(sprintf('No se pudo leer %s', $path));
        }
    }
}
