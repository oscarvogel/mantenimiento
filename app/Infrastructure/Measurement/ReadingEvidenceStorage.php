<?php

declare(strict_types=1);

namespace App\Infrastructure\Measurement;

use RuntimeException;

final class ReadingEvidenceStorage
{
    private readonly string $root;

    public function __construct(?string $root = null)
    {
        $projectRoot = defined('ROOTPATH') ? rtrim((string) ROOTPATH, '\\/') : dirname(__DIR__, 3);
        $defaultRoot = is_dir('/data/priv') || str_starts_with($projectRoot, '/var/www/')
            ? '/data/priv/lecturas'
            : dirname($projectRoot)
                . DIRECTORY_SEPARATOR . basename($projectRoot) . '-private'
                . DIRECTORY_SEPARATOR . 'uploads'
                . DIRECTORY_SEPARATOR . 'lecturas';
        $resolved = rtrim(trim($root ?? $defaultRoot), '\\/');
        if ($resolved === '' || ! preg_match('~^(?:[A-Za-z]:[\\\\/]|/|\\\\\\\\)~', $resolved)) {
            throw new RuntimeException('La ruta privada de evidencias debe ser absoluta.');
        }
        $this->root = $resolved;
    }

    /** @return array{path:string,bytes:int,mime:string} */
    public function store(string $sourcePath, int $companyId, string $mimeType): array
    {
        if ($companyId <= 0 || ! is_file($sourcePath) || ! is_readable($sourcePath)) {
            throw new RuntimeException('La foto de evidencia no está disponible.');
        }

        $extension = match (strtolower($mimeType)) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            default => throw new RuntimeException('La evidencia debe ser una imagen JPG o PNG.'),
        };

        $directory = $this->root . DIRECTORY_SEPARATOR . $companyId;
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('No se pudo crear el directorio privado de evidencias.');
        }

        $storedName = bin2hex(random_bytes(24)) . '.' . $extension;
        $destination = $directory . DIRECTORY_SEPARATOR . $storedName;
        if (! copy($sourcePath, $destination)) {
            throw new RuntimeException('No se pudo guardar la foto de evidencia.');
        }
        @chmod($destination, 0600);

        return [
            'path' => $companyId . '/' . $storedName,
            'bytes' => (int) filesize($destination),
            'mime' => strtolower($mimeType),
        ];
    }

    public function read(string $relativePath, int $companyId): string
    {
        $path = $this->resolve($relativePath, $companyId);
        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException('No se pudo leer la evidencia.');
        }
        return $content;
    }

    public function delete(string $relativePath, int $companyId): void
    {
        try {
            $path = $this->resolve($relativePath, $companyId);
        } catch (RuntimeException) {
            return;
        }
        @unlink($path);
    }

    private function resolve(string $relativePath, int $companyId): string
    {
        if (! preg_match('/^([1-9][0-9]*)\/([a-f0-9]{48}\.(?:jpg|png))$/', $relativePath, $matches)
            || (int) $matches[1] !== $companyId) {
            throw new RuntimeException('La ruta de evidencia no es válida.');
        }

        $root = realpath($this->root);
        $candidate = realpath($this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath));
        if ($root === false || $candidate === false || ! is_file($candidate)) {
            throw new RuntimeException('La evidencia no está disponible.');
        }

        $prefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (! str_starts_with($candidate, $prefix)) {
            throw new RuntimeException('La evidencia sale del almacenamiento privado.');
        }
        return $candidate;
    }
}
