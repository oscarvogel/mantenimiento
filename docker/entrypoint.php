<?php

declare(strict_types=1);

/**
 * Bootstrap del contenedor Coolify.
 *
 * Docker entrega las variables CI4 con puntos al proceso inicial, pero el
 * shell de arranque puede descartarlas antes de iniciar Apache. PHP las lee
 * directamente y las materializa en el .env efímero que CI4 carga desde la
 * raíz del proyecto.
 */

$projectRoot = getcwd() ?: '/var/www/html';

foreach ([
    $projectRoot . '/writable/cache',
    $projectRoot . '/writable/logs',
    $projectRoot . '/writable/session',
    $projectRoot . '/writable/uploads',
    $projectRoot . '/writable/debugbar',
    '/data/priv/adjuntos',
    '/data/priv/importaciones',
] as $directory) {
    if (! is_dir($directory)) {
        @mkdir($directory, 0775, true);
    }
    @chmod($directory, 0755);
    @chown($directory, 'www-data');
}

$environment = getenv();
$lines = [];

foreach ($environment as $key => $value) {
    if ($key !== 'CI_ENVIRONMENT' && ! str_contains($key, '.')) {
        continue;
    }

    $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $value);
    $lines[] = $key . '="' . $escaped . '"';
}

$envFile = $projectRoot . '/.env';
$temporaryEnvFile = $envFile . '.tmp.' . getmypid();

if ($lines !== []) {
    file_put_contents($temporaryEnvFile, implode(PHP_EOL, $lines) . PHP_EOL, LOCK_EX);
    rename($temporaryEnvFile, $envFile);
    @chmod($envFile, 0640);
    @chown($envFile, 'www-data');
} else {
    @unlink($temporaryEnvFile);
}

$command = array_slice($argv, 1);
if ($command === []) {
    $command = ['apache2-foreground'];
}

$quotedCommand = implode(' ', array_map(static fn (string $argument): string => escapeshellarg($argument), $command));
passthru('exec ' . $quotedCommand, $exitCode);
exit($exitCode);
