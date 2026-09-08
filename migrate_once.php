<?php

declare(strict_types=1);

/**
 * Migración puntual de vencimientos.
 *
 * Docker/staging:
 *   php migrate_once.php
 *
 * Producción Ferozo:
 *   subir este archivo a la raíz, abrir /migrate_once.php una vez y borrarlo.
 *
 * No usa php spark. Es idempotente: si las tablas ya existen, solo verifica.
 */

header('Content-Type: application/json; charset=utf-8');

function output(array $payload, int $status = 200): never
{
    if (PHP_SAPI !== 'cli') {
        http_response_code($status);
    }

    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
    exit($status >= 400 ? 1 : 0);
}

/** @return array<string,string> */
function loadEnv(string $path): array
{
    $values = [];

    if (! is_file($path) || ! is_readable($path)) {
        return $values;
    }

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);

        if ($value !== '' && (($value[0] === '"' && str_ends_with($value, '"')) || ($value[0] === "'" && str_ends_with($value, "'")))) {
            $value = substr($value, 1, -1);
        }

        $values[$key] = stripcslashes($value);
    }

    return $values;
}

function envValue(array $env, string $key, ?string $default = null): ?string
{
    $aliases = [$key];

    if (str_starts_with($key, 'database.default.')) {
        $aliases[] = 'database_default_' . substr($key, strlen('database.default.'));
    }

    foreach ($aliases as $alias) {
        $runtime = getenv($alias);
        if ($runtime !== false && $runtime !== '') {
            return (string) $runtime;
        }

        if (isset($env[$alias]) && $env[$alias] !== '') {
            return $env[$alias];
        }
    }

    return $default;
}

function tableExists(mysqli $db, string $table): bool
{
    $safe = $db->real_escape_string($table);
    $result = $db->query("SHOW TABLES LIKE '{$safe}'");

    return $result instanceof mysqli_result && $result->num_rows > 0;
}

$root = __DIR__;
$env = loadEnv($root . '/.env');

$host = envValue($env, 'database.default.hostname', '127.0.0.1');
$database = envValue($env, 'database.default.database');
$username = envValue($env, 'database.default.username');
$password = envValue($env, 'database.default.password', '');
$port = (int) (envValue($env, 'database.default.port', '3306') ?? '3306');

if ($database === null || $database === '' || $username === null || $username === '') {
    output([
        'status' => 'error',
        'message' => 'No se pudo leer la configuración de base de datos.',
    ], 500);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $db = new mysqli((string) $host, (string) $username, (string) $password, (string) $database, $port);
    $db->set_charset('utf8mb4');

    foreach (['empresas', 'sucursales', 'equipos', 'empleados', 'usuarios', 'importaciones'] as $required) {
        if (! tableExists($db, $required)) {
            output([
                'status' => 'error',
                'database' => $database,
                'message' => "Falta la tabla requerida {$required}. No se modificó nada.",
            ], 500);
        }
    }

    $created = [];

    if (! tableExists($db, 'tipos_vencimiento')) {
        $db->query(<<<'SQL'
CREATE TABLE tipos_vencimiento (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  empresa_id INT UNSIGNED NOT NULL,
  nombre VARCHAR(100) NOT NULL,
  aplica_a VARCHAR(20) NOT NULL DEFAULT 'EQUIPO',
  descripcion VARCHAR(500) NULL,
  dias_aviso_previo INT UNSIGNED NOT NULL DEFAULT 30,
  requiere_documento TINYINT(1) NOT NULL DEFAULT 0,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT UNSIGNED NULL,
  updated_by INT UNSIGNED NULL,
  created_at DATETIME NULL,
  updated_at DATETIME NULL,
  deleted_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_expiration_type_company_name (empresa_id,nombre),
  UNIQUE KEY uq_expiration_type_company_id (empresa_id,id),
  KEY idx_expiration_type_scope (empresa_id,activo),
  CONSTRAINT fk_exp_type_company FOREIGN KEY (empresa_id) REFERENCES empresas (id) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT fk_exp_type_created_by FOREIGN KEY (created_by) REFERENCES usuarios (id) ON DELETE SET NULL ON UPDATE RESTRICT,
  CONSTRAINT fk_exp_type_updated_by FOREIGN KEY (updated_by) REFERENCES usuarios (id) ON DELETE SET NULL ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $created[] = 'tipos_vencimiento';
    }

    if (! tableExists($db, 'vencimientos')) {
        $db->query(<<<'SQL'
CREATE TABLE vencimientos (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  empresa_id INT UNSIGNED NOT NULL,
  sucursal_id INT UNSIGNED NULL,
  tipo_vencimiento_id INT UNSIGNED NOT NULL,
  sujeto_tipo VARCHAR(20) NOT NULL,
  equipo_id INT UNSIGNED NULL,
  empleado_id INT UNSIGNED NULL,
  fecha_emision DATE NULL,
  fecha_vencimiento DATE NOT NULL,
  numero_documento VARCHAR(100) NULL,
  observaciones TEXT NULL,
  origen VARCHAR(30) NOT NULL DEFAULT 'MANUAL',
  importacion_id BIGINT UNSIGNED NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT UNSIGNED NULL,
  updated_by INT UNSIGNED NULL,
  created_at DATETIME NULL,
  updated_at DATETIME NULL,
  deleted_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_expiration_equipment_type_date (empresa_id,equipo_id,tipo_vencimiento_id,fecha_vencimiento),
  UNIQUE KEY uq_expiration_employee_type_date (empresa_id,empleado_id,tipo_vencimiento_id,fecha_vencimiento),
  KEY idx_expiration_company_date (empresa_id,fecha_vencimiento,activo),
  KEY idx_expiration_equipment (empresa_id,equipo_id),
  KEY idx_expiration_employee (empresa_id,empleado_id),
  CONSTRAINT fk_exp_company FOREIGN KEY (empresa_id) REFERENCES empresas (id) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT fk_exp_branch FOREIGN KEY (sucursal_id) REFERENCES sucursales (id) ON DELETE SET NULL ON UPDATE RESTRICT,
  CONSTRAINT fk_exp_type FOREIGN KEY (tipo_vencimiento_id) REFERENCES tipos_vencimiento (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_exp_equipment FOREIGN KEY (equipo_id) REFERENCES equipos (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_exp_employee FOREIGN KEY (empleado_id) REFERENCES empleados (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_exp_import FOREIGN KEY (importacion_id) REFERENCES importaciones (id) ON DELETE SET NULL ON UPDATE RESTRICT,
  CONSTRAINT fk_exp_created_by FOREIGN KEY (created_by) REFERENCES usuarios (id) ON DELETE SET NULL ON UPDATE RESTRICT,
  CONSTRAINT fk_exp_updated_by FOREIGN KEY (updated_by) REFERENCES usuarios (id) ON DELETE SET NULL ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $created[] = 'vencimientos';
    }

    output([
        'status' => 'ok',
        'database' => $database,
        'created' => $created,
        'checks' => [
            'tipos_vencimiento' => tableExists($db, 'tipos_vencimiento'),
            'vencimientos' => tableExists($db, 'vencimientos'),
        ],
        'message' => 'Migración aplicada/verificada. En producción, BORRAR migrate_once.php después de ejecutarlo.',
    ]);
} catch (Throwable $exception) {
    output([
        'status' => 'error',
        'database' => $database,
        'message' => $exception->getMessage(),
    ], 500);
}
