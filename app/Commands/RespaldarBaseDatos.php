<?php

declare(strict_types=1);

namespace App\Commands;

use App\Infrastructure\Backup\MysqlDump;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use mysqli;
use Throwable;

/**
 * Respaldo diario de la base de datos, pensado para el cron de Ferozo:
 *
 *   php /ruta/a/la/app/spark respaldo:db
 *
 * Guarda `respaldo-<base>-<fecha>.sql.gz` en `writable/respaldos/` (carpeta que
 * la web no sirve y que no se versiona), verifica que el archivo esté completo
 * y recién entonces borra los respaldos más viejos que la retención. Siempre
 * conserva los últimos tres, aunque sean viejos: si el cron dejó de correr un
 * tiempo, no se queda sin ningún respaldo.
 *
 * Solo lee la base. El respaldo queda en el mismo servidor: para sobrevivir a
 * una pérdida del hosting hay que bajarlo a otro lado cada tanto.
 */
final class RespaldarBaseDatos extends BaseCommand
{
    private const MINIMUM_KEPT = 3;

    protected $group       = 'Mantenimiento';
    protected $name        = 'respaldo:db';
    protected $description = 'Respalda la base de datos en writable/respaldos/ y aplica la retención.';
    protected $usage       = 'respaldo:db [--dias=14] [--carpeta=writable/respaldos]';
    protected $options     = [
        '--dias'    => 'Días de retención. Por defecto 14.',
        '--carpeta' => 'Carpeta destino relativa a la raíz de la app. Por defecto writable/respaldos.',
    ];

    public function run(array $params): int
    {
        $days = max(1, (int) (CLI::getOption('dias') ?? $params['dias'] ?? 14));
        $folder = $this->folder((string) (CLI::getOption('carpeta') ?? $params['carpeta'] ?? 'writable/respaldos'));

        try {
            if (! is_dir($folder) && ! mkdir($folder, 0750, true) && ! is_dir($folder)) {
                throw new \RuntimeException("No se pudo crear la carpeta {$folder}.");
            }
            $this->protect($folder);

            $connection = db_connect();
            $connection->initialize();
            $mysqli = $connection->connID;
            if (! $mysqli instanceof mysqli) {
                throw new \RuntimeException('El respaldo necesita el driver MySQLi.');
            }
            $mysqli->set_charset('utf8mb4');

            $database = $connection->getDatabase();
            $path = $folder . DIRECTORY_SEPARATOR . 'respaldo-' . preg_replace('/[^A-Za-z0-9_-]/', '_', $database) . '-' . date('Ymd-His') . '.sql.gz';

            $started = microtime(true);
            $stats = (new MysqlDump($mysqli))->dumpToGzipFile($database, $path);

            if (! MysqlDump::isComplete($path)) {
                throw new \RuntimeException("El respaldo {$path} quedó incompleto. No se borró ningún respaldo anterior.");
            }

            $removed = $this->applyRetention($folder, $days, $path);

            CLI::write(sprintf(
                'RESPALDO=OK archivo=%s tablas=%d vistas=%d filas=%d bytes=%d segundos=%.1f borrados=%d',
                basename($path),
                $stats['tables'],
                $stats['views'],
                $stats['rows'],
                $stats['bytes'],
                microtime(true) - $started,
                $removed,
            ));
            log_message('info', 'Respaldo de base de datos OK: {file} ({rows} filas)', ['file' => basename($path), 'rows' => $stats['rows']]);

            return EXIT_SUCCESS;
        } catch (Throwable $exception) {
            CLI::error('RESPALDO=ERROR ' . $exception->getMessage());
            log_message('error', 'Respaldo de base de datos falló: {message}', ['message' => $exception->getMessage()]);

            return EXIT_ERROR;
        }
    }

    private function folder(string $folder): string
    {
        $folder = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $folder), DIRECTORY_SEPARATOR);
        $isAbsolute = str_starts_with($folder, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:/', $folder) === 1;

        return $isAbsolute ? $folder : ROOTPATH . $folder;
    }

    /** Por si la carpeta queda fuera de writable/: que Apache tampoco la sirva. */
    private function protect(string $folder): void
    {
        $htaccess = $folder . DIRECTORY_SEPARATOR . '.htaccess';
        if (! is_file($htaccess)) {
            file_put_contents($htaccess, "<IfModule authz_core_module>\n\tRequire all denied\n</IfModule>\n<IfModule !authz_core_module>\n\tDeny from all\n</IfModule>\n");
        }
        $index = $folder . DIRECTORY_SEPARATOR . 'index.html';
        if (! is_file($index)) {
            file_put_contents($index, '');
        }
    }

    private function applyRetention(string $folder, int $days, string $current): int
    {
        $limit = time() - $days * 86400;
        $removed = 0;

        foreach (glob($folder . DIRECTORY_SEPARATOR . 'respaldo-*.sql.gz.parcial') ?: [] as $partial) {
            if (filemtime($partial) < time() - 86400 && @unlink($partial)) {
                $removed++;
            }
        }

        $backups = glob($folder . DIRECTORY_SEPARATOR . 'respaldo-*.sql.gz') ?: [];
        usort($backups, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

        foreach (array_slice($backups, self::MINIMUM_KEPT) as $backup) {
            if ($backup !== $current && filemtime($backup) < $limit && @unlink($backup)) {
                $removed++;
            }
        }

        return $removed;
    }
}
