<?php

declare(strict_types=1);

namespace App\Infrastructure\Backup;

use mysqli;
use RuntimeException;

/**
 * Volcado SQL de una base MySQL/MariaDB, en PHP puro y sin dependencias de
 * CodeIgniter: lo usan el comando `db:respaldar` (cron diario) y el script
 * puntual `respaldo_db.php` de la raíz, que no arranca el framework.
 *
 * Solo lee. Recorre las tablas sin cargarlas en memoria (MYSQLI_USE_RESULT),
 * escribe INSERT por lotes, binarios en hexadecimal y deja las vistas al final.
 * La última línea dice cuántas tablas y filas se volcaron: un archivo sin esa
 * línea quedó cortado.
 */
final class MysqlDump
{
    public const FOOTER_PREFIX = '-- Fin del respaldo:';

    private const ROWS_PER_INSERT = 500;
    private const BINARY_TYPES = [
        MYSQLI_TYPE_BLOB, MYSQLI_TYPE_TINY_BLOB, MYSQLI_TYPE_MEDIUM_BLOB,
        MYSQLI_TYPE_LONG_BLOB, MYSQLI_TYPE_STRING, MYSQLI_TYPE_VAR_STRING,
    ];
    private const BINARY_CHARSET = 63;

    public function __construct(private readonly mysqli $db)
    {
    }

    /**
     * @param callable(string):void $write recibe el SQL en trozos
     * @return array{tables:int,views:int,rows:int}
     */
    public function dump(string $database, callable $write): array
    {
        [$tables, $views] = $this->objects();

        $write("-- Respaldo de {$database}\n-- Generado: " . date('Y-m-d H:i:s') . "\n\n");
        $write("SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\nSET time_zone='+00:00';\n\n");

        $totalRows = 0;
        foreach ($tables as $table) {
            $totalRows += $this->dumpTable($table, $write);
        }

        foreach ($views as $view) {
            $quoted = self::quote($view);
            $create = (string) $this->db->query('SHOW CREATE VIEW ' . $quoted)->fetch_row()[1];
            $create = (string) preg_replace('/DEFINER=`[^`]*`@`[^`]*`\s*/', '', $create);
            $write("DROP VIEW IF EXISTS {$quoted};\n{$create};\n\n");
        }

        $write("SET FOREIGN_KEY_CHECKS=1;\n" . self::FOOTER_PREFIX . ' ' . count($tables) . ' tablas, ' . count($views) . " vistas, {$totalRows} filas.\n");

        return ['tables' => count($tables), 'views' => count($views), 'rows' => $totalRows];
    }

    /** @return array<string,int> filas por tabla */
    public function rowCounts(): array
    {
        $counts = [];
        foreach ($this->objects()[0] as $table) {
            $counts[$table] = (int) $this->db->query('SELECT COUNT(*) FROM ' . self::quote($table))->fetch_row()[0];
        }

        return $counts;
    }

    /**
     * Escribe el volcado comprimido en $path. Primero en un temporal y recién al
     * final lo renombra, así nunca queda un .sql.gz a medias con nombre válido.
     *
     * @return array{tables:int,views:int,rows:int,bytes:int}
     */
    public function dumpToGzipFile(string $database, string $path): array
    {
        $temporary = $path . '.parcial';
        $handle = gzopen($temporary, 'wb6');
        if ($handle === false) {
            throw new RuntimeException("No se pudo crear {$temporary}.");
        }

        try {
            $stats = $this->dump($database, static function (string $chunk) use ($handle, $temporary): void {
                if (gzwrite($handle, $chunk) === false) {
                    throw new RuntimeException("No se pudo escribir {$temporary}.");
                }
            });
        } catch (\Throwable $exception) {
            gzclose($handle);
            @unlink($temporary);
            throw $exception;
        }

        gzclose($handle);
        if (! rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException("No se pudo guardar {$path}.");
        }

        return $stats + ['bytes' => (int) filesize($path)];
    }

    /** Verifica que el .sql.gz se pueda leer entero y termine con la línea de cierre. */
    public static function isComplete(string $path): bool
    {
        $handle = @gzopen($path, 'rb');
        if ($handle === false) {
            return false;
        }

        $last = '';
        while (! gzeof($handle)) {
            $line = gzgets($handle, 1 << 20);
            if ($line === false) {
                break;
            }
            if (trim($line) !== '') {
                $last = $line;
            }
        }
        gzclose($handle);

        return str_starts_with($last, self::FOOTER_PREFIX);
    }

    /** @return array{0:list<string>,1:list<string>} tablas y vistas */
    private function objects(): array
    {
        $tables = [];
        $views = [];
        foreach ($this->db->query('SHOW FULL TABLES')->fetch_all(MYSQLI_NUM) as [$name, $type]) {
            if ($type === 'VIEW') {
                $views[] = (string) $name;
            } else {
                $tables[] = (string) $name;
            }
        }

        return [$tables, $views];
    }

    /** @param callable(string):void $write */
    private function dumpTable(string $table, callable $write): int
    {
        $quoted = self::quote($table);
        $create = (string) $this->db->query('SHOW CREATE TABLE ' . $quoted)->fetch_row()[1];
        $write("--\n-- Tabla {$table}\n--\nDROP TABLE IF EXISTS {$quoted};\n{$create};\n\n");

        $result = $this->db->query('SELECT * FROM ' . $quoted, MYSQLI_USE_RESULT);
        $fields = $result->fetch_fields();
        $columns = implode(', ', array_map(static fn ($field): string => self::quote($field->name), $fields));
        $binary = array_map(
            static fn ($field): bool => in_array($field->type, self::BINARY_TYPES, true) && $field->charsetnr === self::BINARY_CHARSET,
            $fields,
        );

        $batch = [];
        $rows = 0;
        while ($row = $result->fetch_row()) {
            $values = [];
            foreach ($row as $i => $value) {
                if ($value === null) {
                    $values[] = 'NULL';
                } elseif ($binary[$i]) {
                    $values[] = $value === '' ? "''" : '0x' . bin2hex((string) $value);
                } else {
                    $values[] = "'" . $this->db->real_escape_string((string) $value) . "'";
                }
            }
            $batch[] = '(' . implode(',', $values) . ')';
            $rows++;
            if (count($batch) >= self::ROWS_PER_INSERT) {
                $write("INSERT INTO {$quoted} ({$columns}) VALUES\n" . implode(",\n", $batch) . ";\n");
                $batch = [];
            }
        }
        $result->free();

        if ($batch !== []) {
            $write("INSERT INTO {$quoted} ({$columns}) VALUES\n" . implode(",\n", $batch) . ";\n");
        }
        $write("-- {$rows} filas\n\n");

        return $rows;
    }

    private static function quote(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }
}
