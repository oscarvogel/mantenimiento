<?php

declare(strict_types=1);

if ($argc !== 3) {
    fwrite(STDERR, "Uso: php -d extension=zip scripts/build-ferozo-archive.php <release-dir> <archivo.zip>\n");
    exit(2);
}

$source = realpath($argv[1]);
$destination = $argv[2];
if ($source === false || ! is_dir($source)) {
    throw new RuntimeException('El directorio de release no existe.');
}
if (file_exists($destination)) {
    throw new RuntimeException('El archivo destino ya existe; no se sobrescribe.');
}
if (! class_exists(ZipArchive::class)) {
    throw new RuntimeException('La extension zip no esta disponible.');
}

$zip = new ZipArchive();
if ($zip->open($destination, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
    throw new RuntimeException('No se pudo crear el archivo de release.');
}

$files = 0;
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST,
);

try {
    foreach ($iterator as $item) {
        if ($item->isLink()) {
            throw new RuntimeException('El release no puede contener enlaces simbolicos.');
        }

        $path = $item->getPathname();
        $relative = str_replace('\\', '/', substr($path, strlen($source) + 1));
        if ($relative === '' || str_starts_with($relative, '/') || preg_match('~(^|/)\.\.(/|$)~', $relative)) {
            throw new RuntimeException('El release contiene una ruta insegura.');
        }

        if ($item->isDir()) {
            $zip->addEmptyDir($relative);
            continue;
        }
        if (! $item->isFile() || ! $zip->addFile($path, $relative)) {
            throw new RuntimeException('No se pudo agregar un archivo al paquete.');
        }
        $files++;
    }
} catch (Throwable $error) {
    $zip->close();
    if (is_file($destination)) {
        unlink($destination);
    }
    throw $error;
}

if (! $zip->close()) {
    throw new RuntimeException('No se pudo cerrar el archivo de release.');
}

echo sprintf("ARCHIVE_OK files=%d bytes=%d\n", $files, filesize($destination));
