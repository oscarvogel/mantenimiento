<?php

declare(strict_types=1);

namespace App\Application\Chatbot\Support;

/**
 * Normaliza el texto que viene del provider para ChatWidget.
 *
 * - Convierte tablas markdown (| col | col |) en bullets "- col: valor"
 *   porque el modelo MiniMax-M3 insiste en tabular aunque se lo
 *   prohibamos en el system prompt. Asi garantizamos el formato.
 * - Quita bloques <think>...</think> por si quedara alguno residual.
 * - NO toca URLs (eso lo hace el frontend si el usuario lo requiere).
 */
final class MarkdownTextCleaner
{
    public static function normalize(string $text): string
    {
        $text = self::stripThinking($text);
        $text = self::tablesToBullets($text);
        // Compactar mas de 3 lineas en blanco seguidas
        $text = preg_replace("/\n{4,}/", "\n\n\n", $text) ?? $text;
        return trim($text);
    }

    private static function stripThinking(string $text): string
    {
        return preg_replace('/<think>.*?<\/think>/s', '', $text) ?? $text;
    }

    /**
     * Convierte cada tabla markdown en una lista de bullets.
     * Tolera tablas con o sin linea separadora (|---|---|).
     * La primera fila es el header (etiqueta de columna).
     * Si la tabla tiene N filas, produce 1 bullet por fila de datos.
     */
    private static function tablesToBullets(string $text): string
    {
        $pattern = '/(?:^\||\G\|)((?:[^\n|]\|?)+)\n\|?\s*[-:|\s]+\|?\s*\n((?:\|[^\n]+\n?)+?)(?=\n\s*\n|\n\s*[^\n|]|\Z)/m';

        return preg_replace_callback($pattern, static function (array $m): string {
            $headerLine = trim((string) $m[1], '|');
            $headers = array_map(static fn (string $h): string => trim($h), explode('|', $headerLine));

            $body = trim((string) $m[2], "\n");
            $rows = array_filter(array_map(static fn (string $r): string => trim($r, '|'), explode("\n", $body)));
            if ($rows === [] || $headers === []) {
                return '';
            }

            $lines = [];
            foreach ($rows as $row) {
                $cells = array_map(static fn (string $c): string => trim($c), explode('|', $row));
                $count = min(count($cells), count($headers));
                if ($count === 0) {
                    continue;
                }
                // 1ra columna = nombre/identificador; resto = "label: valor".
                $first = $cells[0] ?? '';
                $rest = [];
                for ($i = 1; $i < $count; $i++) {
                    $label = $headers[$i] ?? '';
                    $value = $cells[$i] ?? '';
                    if ($value === '' || $label === '') {
                        continue;
                    }
                    $rest[] = $label . ': ' . $value;
                }
                if ($rest === []) {
                    $lines[] = '- ' . $first;
                } else {
                    $lines[] = '- ' . $first . ' (' . implode(', ', $rest) . ')';
                }
            }

            return implode("\n", $lines) . "\n";
        }, (string) $text) ?? $text;
    }
}
