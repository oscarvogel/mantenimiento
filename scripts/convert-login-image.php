<?php

declare(strict_types=1);

$sourcePath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'ChatGPT Image 8 ago 2026, 17_07_41.png';
$targetPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'login'
    . DIRECTORY_SEPARATOR . 'maintenance-workshop.webp';

if (! extension_loaded('gd') || ! function_exists('imagewebp')) {
    fwrite(STDERR, "La conversión requiere la extensión GD con soporte WebP.\n");
    exit(1);
}

$source = imagecreatefrompng($sourcePath);
if ($source === false) {
    fwrite(STDERR, "No se pudo leer la imagen PNG de origen.\n");
    exit(1);
}

imagepalettetotruecolor($source);
$converted = imagewebp($source, $targetPath, 82);
imagedestroy($source);

if (! $converted) {
    fwrite(STDERR, "No se pudo escribir el archivo WebP.\n");
    exit(1);
}

fwrite(STDOUT, "Imagen WebP generada en assets/login/maintenance-workshop.webp\n");
