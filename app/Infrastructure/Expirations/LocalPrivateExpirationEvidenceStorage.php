<?php

declare(strict_types=1);

namespace App\Infrastructure\Expirations;

use App\Application\Assets\Attachment\StoredAttachmentFile;
use App\Application\Expirations\Port\ExpirationEvidenceStorage;
use RuntimeException;

/**
 * Almacenamiento privado de las evidencias de renovacion. Misma idea que
 * LocalPrivateAttachmentStorage para equipos, pero separado para mantener
 * los limites del bounded context: el filesystem privado vive afuera del
 * document root y los nombres son opacos (48 hex chars + extension).
 */
final class LocalPrivateExpirationEvidenceStorage implements ExpirationEvidenceStorage
{
    private readonly string $root;
    private readonly string $projectRoot;

    public function __construct(?string $root = null)
    {
        $projectRoot = defined('ROOTPATH')
            ? rtrim((string) ROOTPATH, '\\/')
            : dirname(__DIR__, 4);
        $this->projectRoot = $projectRoot;
        $defaultRoot = dirname($projectRoot)
            . DIRECTORY_SEPARATOR . basename($projectRoot) . '-private'
            . DIRECTORY_SEPARATOR . 'uploads'
            . DIRECTORY_SEPARATOR . 'vencimientos';
        $resolvedRoot = rtrim(trim($root ?? $defaultRoot), '\\/');
        if ($resolvedRoot === '') {
            throw new RuntimeException('La raiz privada de evidencias no puede estar vacia.');
        }
        if (! preg_match('~^(?:[A-Za-z]:[\\\\/]|/|\\\\\\\\)~', $resolvedRoot)) {
            throw new RuntimeException('La raiz privada de evidencias debe ser una ruta absoluta.');
        }
        $this->assertOutsidePublicProject($resolvedRoot);
        $this->root = $resolvedRoot;
    }

    public function store(string $sourcePath, int $companyId, string $extension): StoredAttachmentFile
    {
        if ($companyId <= 0 || ! is_file($sourcePath) || ! is_readable($sourcePath)) {
            throw new RuntimeException('No se puede almacenar la evidencia indicada.');
        }
        $extension = strtolower(trim($extension));
        if (! in_array($extension, ['pdf', 'jpg', 'png', 'webp'], true)) {
            throw new RuntimeException('La extension de almacenamiento no esta permitida.');
        }

        $companyDirectory = $this->root . DIRECTORY_SEPARATOR . $companyId;
        if (! is_dir($companyDirectory)
            && ! mkdir($companyDirectory, 0700, true)
            && ! is_dir($companyDirectory)) {
            throw new RuntimeException('No se pudo crear el directorio privado de evidencias.');
        }
        $realRoot = realpath($this->root);
        $realCompanyDirectory = realpath($companyDirectory);
        if ($realRoot === false) {
            throw new RuntimeException('No se pudo resolver la raiz privada de evidencias.');
        }
        if ($realCompanyDirectory === false
            || ! str_starts_with(
                $realCompanyDirectory . DIRECTORY_SEPARATOR,
                rtrim($realRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR,
            )) {
            throw new RuntimeException('El directorio de empresa sale del almacenamiento privado.');
        }

        $storedName = bin2hex(random_bytes(24)) . '.' . $extension;
        $destination = $realCompanyDirectory . DIRECTORY_SEPARATOR . $storedName;
        $source = fopen($sourcePath, 'rb');
        $target = fopen($destination, 'xb');
        if ($source === false || $target === false) {
            if (is_resource($source)) {
                fclose($source);
            }
            if (is_resource($target)) {
                fclose($target);
            }
            @unlink($destination);
            throw new RuntimeException('No se pudo abrir el almacenamiento privado de la evidencia.');
        }

        try {
            if (stream_copy_to_stream($source, $target) === false) {
                throw new RuntimeException('No se pudo copiar la evidencia al almacenamiento privado.');
            }
        } catch (\Throwable $exception) {
            fclose($source);
            fclose($target);
            @unlink($destination);
            throw $exception;
        }
        fclose($source);
        fclose($target);
        @chmod($destination, 0600);

        return new StoredAttachmentFile($storedName, $companyId . '/' . $storedName);
    }

    public function read(string $privateRelativePath): string
    {
        $path = $this->resolveExistingPath($privateRelativePath);
        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException('No se pudo leer la evidencia privada.');
        }

        return $content;
    }

    public function delete(string $privateRelativePath): void
    {
        $this->assertRelativePath($privateRelativePath);
        $candidate = $this->root . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $privateRelativePath);
        if (! file_exists($candidate) && ! is_link($candidate)) {
            return;
        }
        $path = $this->resolveExistingPath($privateRelativePath);
        if (! unlink($path)) {
            throw new RuntimeException('No se pudo compensar el archivo privado almacenado.');
        }
    }

    private function resolveExistingPath(string $privateRelativePath): string
    {
        $this->assertRelativePath($privateRelativePath);
        $root = realpath($this->root);
        $path = realpath(
            $this->root . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $privateRelativePath),
        );
        if ($root === false || $path === false || ! is_file($path)) {
            throw new RuntimeException('La evidencia privada no esta disponible.');
        }
        $prefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (! str_starts_with($path, $prefix)) {
            throw new RuntimeException('La ruta de la evidencia sale del almacenamiento privado.');
        }

        return $path;
    }

    private function assertRelativePath(string $privateRelativePath): void
    {
        if (! preg_match('/^[1-9][0-9]*\/[a-f0-9]{48}\.(pdf|jpg|png|webp)$/', $privateRelativePath)) {
            throw new RuntimeException('La ruta privada de la evidencia no es valida.');
        }
    }

    private function assertOutsidePublicProject(string $path): void
    {
        $normalizedPath = strtolower(rtrim(str_replace('\\', '/', $path), '/'));
        $normalizedProject = strtolower(rtrim(str_replace('\\', '/', $this->projectRoot), '/'));
        if ($normalizedPath === $normalizedProject || str_starts_with($normalizedPath . '/', $normalizedProject . '/')) {
            throw new RuntimeException('La raiz de evidencias debe estar fuera del directorio publico del proyecto.');
        }
    }
}
