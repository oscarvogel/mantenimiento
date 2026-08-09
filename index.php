<?php

use CodeIgniter\Boot;
use Config\Paths;

// El servidor de desarrollo de PHP ejecuta este archivo como router. Servir
// unicamente assets publicos existentes y enviar el resto a CodeIgniter evita
// exponer codigo PHP o directorios internos en el layout plano.
if (PHP_SAPI === 'cli-server') {
    $requestPath = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
    $assetsRoot = realpath(__DIR__ . DIRECTORY_SEPARATOR . 'assets');
    $requestedFile = realpath(__DIR__ . DIRECTORY_SEPARATOR . ltrim($requestPath, '/\\'));
    $allowedExtensions = ['css', 'js', 'map', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'ico', 'woff', 'woff2'];

    if ($requestPath === '/favicon.ico' && $requestedFile === realpath(__DIR__ . DIRECTORY_SEPARATOR . 'favicon.ico')) {
        return false;
    }

    if ($assetsRoot !== false
        && $requestedFile !== false
        && is_file($requestedFile)
        && str_starts_with($requestedFile, $assetsRoot . DIRECTORY_SEPARATOR)
        && in_array(strtolower(pathinfo($requestedFile, PATHINFO_EXTENSION)), $allowedExtensions, true)) {
        return false;
    }
}

/*
 *---------------------------------------------------------------
 * CHECK PHP VERSION
 *---------------------------------------------------------------
 */

$minPhpVersion = '8.2'; // If you update this, don't forget to update `spark`.
if (version_compare(PHP_VERSION, $minPhpVersion, '<')) {
    $message = sprintf(
        'Your PHP version must be %s or higher to run CodeIgniter. Current version: %s',
        $minPhpVersion,
        PHP_VERSION,
    );

    header('HTTP/1.1 503 Service Unavailable.', true, 503);
    echo $message;

    exit(1);
}

/*
 *---------------------------------------------------------------
 * SET THE CURRENT DIRECTORY
 *---------------------------------------------------------------
 */

// Path to the front controller (this file)
define('FCPATH', __DIR__ . DIRECTORY_SEPARATOR);

// Ensure the current directory is pointing to the front controller's directory
if (getcwd() . DIRECTORY_SEPARATOR !== FCPATH) {
    chdir(FCPATH);
}

/*
 *---------------------------------------------------------------
 * BOOTSTRAP THE APPLICATION
 *---------------------------------------------------------------
 * This process sets up the path constants, loads and registers
 * our autoloader, along with Composer's, loads our constants
 * and fires up an environment-specific bootstrapping.
 */

// LOAD OUR PATHS CONFIG FILE
// This is the line that might need to be changed, depending on your folder structure.
require FCPATH . 'app/Config/Paths.php';
// ^^^ En este deploy la app vive en el mismo directorio que index.php (estructura plana para FTP).

$paths = new Paths();

// LOAD THE FRAMEWORK BOOTSTRAP FILE
require $paths->systemDirectory . '/Boot.php';

exit(Boot::bootWeb($paths));
