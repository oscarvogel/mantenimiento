<?php

declare(strict_types=1);

/**
 * Verificación puntual para el deploy del issue #131.
 *
 * Este script NO reemplaza scripts/migrate.php y NO modifica la base.
 * Sirve para confirmar, sin usar `php spark`, que los campos demo existen
 * después de ejecutar migrate.php en producción.
 *
 * Uso en Ferozo:
 *   1. subir temporalmente a la raíz del release como verify-demo-migration.php;
 *   2. invocar por HTTP con el mismo X-Migrate-Token usado por migrate.php;
 *   3. borrar inmediatamente después de verificar.
 */

if (PHP_SAPI === 'cli') {
    fwrite(STDERR, "Este script está pensado para verificación HTTP en hosting compartido.\n");
    exit(2);
}

define('FCPATH', __DIR__ . DIRECTORY_SEPARATOR);
chdir(FCPATH);

$envFile = FCPATH . '.env';
if (! is_file($envFile) || ! is_readable($envFile)) {
    http_response_code(500);
    exit("ERROR: .env no disponible.\n");
}

$env = [];
foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#')) {
        continue;
    }
    if (preg_match('/^([A-Za-z0-9_.\-]+)\s*=\s*(.*)$/', $line, $m) !== 1) {
        continue;
    }
    $value = trim($m[2]);
    if ((str_starts_with($value, "'") && str_ends_with($value, "'")) || (str_starts_with($value, '"') && str_ends_with($value, '"'))) {
        $value = substr($value, 1, -1);
    }
    $env[$m[1]] = $value;
}

$expectedToken = $env['MIGRATE_TOKEN'] ?? '';
$providedToken = $_SERVER['HTTP_X_MIGRATE_TOKEN'] ?? '';
if ($expectedToken === '' || ! is_string($providedToken) || ! hash_equals($expectedToken, $providedToken)) {
    http_response_code(401);
    exit("ERROR: token inválido.\n");
}

$host = $env['database.default.hostname'] ?? 'localhost';
$user = $env['database.default.username'] ?? '';
$pass = $env['database.default.password'] ?? '';
$name = $env['database.default.database'] ?? '';
$port = (int) ($env['database.default.port'] ?? 3306);

$mysqli = @new mysqli($host, $user, $pass, $name, $port);
if ($mysqli->connect_errno !== 0) {
    http_response_code(500);
    exit("ERROR: no se pudo conectar a la base.\n");
}

$result = $mysqli->query("SHOW COLUMNS FROM empresas WHERE Field IN ('es_demo', 'demo_expira_at')");
if ($result === false) {
    http_response_code(500);
    exit("ERROR: no se pudo inspeccionar empresas.\n");
}

$found = [];
while ($row = $result->fetch_assoc()) {
    $found[] = $row['Field'];
}
$mysqli->close();

sort($found);
$expected = ['demo_expira_at', 'es_demo'];

header('Content-Type: text/plain; charset=utf-8');
if ($found !== $expected) {
    http_response_code(500);
    echo 'ERROR: faltan campos demo. Encontrados: ' . implode(', ', $found) . "\n";
    exit(1);
}

echo "OK: empresas.es_demo presente.\n";
echo "OK: empresas.demo_expira_at presente.\n";
echo "Sin cambios realizados en la base.\n";
echo "Recordatorio: borrar verify-demo-migration.php después de usarlo.\n";
