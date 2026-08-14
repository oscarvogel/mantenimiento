<?php

declare(strict_types=1);

/**
 * Script de migración remota para deploys en Ferozo (sin SSH ni CLI).
 *
 * Uso:
 *   1. Subir este archivo a la RAIZ del release como `migrate.php` por FTPS.
 *   2. Definir `MIGRATE_TOKEN=<random-seguro>` en el `.env` de produccion.
 *   3. Ejecutar:
 *        curl -H "X-Migrate-Token: <token>" \
 *             https://vogelconsultoria.com.ar/mantenimiento/migrate.php
 *      Para solo ver el estado, agregar `?status=1`.
 *   4. Borrar el archivo por FTPS inmediatamente despues de usarlo.
 *
 * Seguridad:
 *   - El script exige el header `X-Migrate-Token` y compara contra `MIGRATE_TOKEN`.
 *   - Si `CI_ENVIRONMENT` no es `production`, el script rechaza correr.
 *   - El script imprime la IP del cliente y el timestamp en cada ejecucion.
 *   - El archivo debe borrarse despues de cada deploy; queda documentado al final.
 *
 * No versionar credenciales. No commitear este archivo modificado para "dejarlo
 * permanente" en el repo: es un helper de deploy, no parte de la aplicacion.
 */

use CodeIgniter\Boot;
use CodeIgniter\Database\MigrationRunner;
use Config\Paths;

if (PHP_SAPI === 'cli') {
    fwrite(STDERR, "Este script solo se ejecuta por HTTP.\n");
    exit(2);
}

// 1. FCPATH y bootstrap minimo para poder leer el .env
//    El deploy usa layout plano: este archivo vive al lado de index.php / spark.
define('FCPATH', __DIR__ . DIRECTORY_SEPARATOR);
chdir(FCPATH);

require FCPATH . 'app/Config/Paths.php';
$paths = new Paths();

// Carga minima del .env sin levantar el framework completo.
$envFile = FCPATH . '.env';
if (! is_file($envFile) || ! is_readable($envFile)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "ERROR: no se encontro .env leible en la raiz del release.\n";
    exit(1);
}

$env = [];
foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#')) {
        continue;
    }
    if (! preg_match('/^([A-Za-z0-9_.\-]+)\s*=\s*(.*)$/', $line, $m)) {
        continue;
    }
    $key   = $m[1];
    $value = trim($m[2]);
    if (
        (str_starts_with($value, "'") && str_ends_with($value, "'"))
        || (str_starts_with($value, '"') && str_ends_with($value, '"'))
    ) {
        $value = substr($value, 1, -1);
    }
    $env[$key] = $value;
}

$environment = $env['CI_ENVIRONMENT'] ?? 'production';
if ($environment !== 'production') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "ERROR: este script solo corre en produccion. CI_ENVIRONMENT='$environment'.\n";
    exit(1);
}
defined('ENVIRONMENT') || define('ENVIRONMENT', $environment);

$expectedToken = $env['MIGRATE_TOKEN'] ?? '';
if ($expectedToken === '' || strlen($expectedToken) < 32) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "ERROR: MIGRATE_TOKEN no esta definido o es demasiado corto (>=32 chars) en .env.\n";
    exit(1);
}

// 2. Validar el token enviado por el operador.
$providedToken = $_SERVER['HTTP_X_MIGRATE_TOKEN'] ?? '';
if (! is_string($providedToken) || ! hash_equals($expectedToken, $providedToken)) {
    http_response_code(401);
    header('Content-Type: text/plain; charset=utf-8');
    echo "ERROR: token invalido o ausente (header X-Migrate-Token).\n";
    exit(1);
}

$clientIp  = $_SERVER['REMOTE_ADDR'] ?? 'desconocida';
$timestamp = gmdate('Y-m-d\TH:i:s\Z');
$action    = (isset($_GET['status']) && $_GET['status'] === '1') ? 'status' : 'migrate';

header('Content-Type: text/plain; charset=utf-8');
$header = [
    '=== Mantenimiento :: migracion remota ===',
    "Timestamp (UTC): $timestamp",
    "Cliente:         $clientIp",
    "Accion:          $action",
    'Release path:    ' . FCPATH,
    '----------------------------------------',
];

// 3. Bootstrap minimo del framework sin despachar controladores web.
require $paths->systemDirectory . '/Boot.php';
(new class extends Boot {
    public static function bootForMigrations(Paths $paths): void
    {
        static::definePathConstants($paths);
        if (! defined('APP_NAMESPACE')) {
            static::loadConstants();
        }
        static::checkMissingExtensions();
        static::loadDotEnv($paths);
        static::defineEnvironment();
        static::loadEnvironmentBootstrap($paths);
        static::loadCommonFunctions();
        static::loadAutoloader();
        static::setExceptionHandler();
        static::initializeKint();
        static::autoloadHelpers();
    }
})::bootForMigrations($paths);

echo implode("\n", $header) . "\n";

// 4. Acceder al runner de migraciones.
try {
    /** @var MigrationRunner $runner */
    $runner = service('migrations');
} catch (Throwable $e) {
    echo "ERROR: no se pudo obtener el servicio 'migrations': " . $e->getMessage() . "\n";
    exit(1);
}

$runner->clearCliMessages();
$group = $env['database.default.DBDriver'] === 'MySQLi' ? 'default' : null;

if ($action === 'status') {
    $history = $runner->getHistory();
    echo "Migraciones aplicadas hasta el momento:\n";
    if ($history === []) {
        echo "  (sin migraciones registradas)\n";
    } else {
        foreach ($history as $entry) {
            $version = $entry->version ?? '?';
            $name    = $entry->name ?? '?';
            echo "  - $version  $name\n";
        }
    }
    echo "----------------------------------------\n";
    echo "OK: estado reportado. Sin cambios en la base.\n";
    echo "Recordatorio: borrar migrate.php por FTPS cuando termine el deploy.\n";
    exit(0);
}

// 5. Correr migraciones.
try {
    $ok = $runner->latest($group);
} catch (Throwable $e) {
    echo "ERROR al correr migraciones: " . $e->getMessage() . "\n";
    echo "Traza:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}

foreach ($runner->getCliMessages() as $message) {
    echo $message . "\n";
}

if (! $ok) {
    echo "ERROR: el runner devolvio false. Revisar logs en writable/logs/.\n";
    exit(1);
}

echo "----------------------------------------\n";
echo "OK: migraciones aplicadas.\n";
echo "Recordatorio: borrar migrate.php por FTPS cuando termine el deploy.\n";
exit(0);
