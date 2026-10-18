<?php

declare(strict_types=1);

namespace App\Infrastructure\Importations;

use App\Application\Importations\Port\PrivateImportFileStorage;
use App\Application\Importations\StoredImportFile;
use DomainException;
use RuntimeException;

final class LocalPrivateImportFileStorage implements PrivateImportFileStorage
{
    private const ALLOWED_EXTENSIONS = ['csv', 'xlsx', 'zip'];
    private const MAXIMUM_ZIP_ENTRIES = 2_000;

    private readonly string $storageRoot;

    public function __construct(
        string $storageRoot,
        private readonly int $maximumBytes = 10_485_760,
        private readonly int $maximumZipUncompressedBytes = 134_217_728,
    ) {
        $root = rtrim(str_replace('\\', '/', $storageRoot), '/');
        $projectRoot = defined('ROOTPATH') ? rtrim(str_replace('\\', '/', (string) ROOTPATH), '/') : null;
        if ($root === '' || ! preg_match('~^(?:[A-Za-z]:/|/|//)~', $root)
            || ($projectRoot !== null && str_starts_with(mb_strtolower($root . '/'), mb_strtolower($projectRoot . '/')))) {
            throw new DomainException('La raiz de importaciones debe configurarse fuera del directorio publico del proyecto.');
        }
        $this->storageRoot = str_replace('/', DIRECTORY_SEPARATOR, $root);
    }

    public function store(string $uploadedPath, string $originalName): StoredImportFile
    {
        if (! is_file($uploadedPath) || ! is_readable($uploadedPath)) {
            throw new DomainException('El archivo subido no esta disponible.');
        }
        $size = filesize($uploadedPath);
        if ($size === false || $size <= 0 || $size > $this->maximumBytes) {
            throw new DomainException('El archivo esta vacio o supera el limite de 10 MB.');
        }
        $safeOriginal = basename(str_replace('\\', '/', trim($originalName)));
        if ($safeOriginal === '' || mb_strlen($safeOriginal) > 255) {
            throw new DomainException('El nombre original del archivo es invalido o supera 255 caracteres.');
        }
        $extension = strtolower(pathinfo($safeOriginal, PATHINFO_EXTENSION));
        if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new DomainException('Solo se admiten archivos CSV, XLSX o paquetes ZIP.');
        }
        $signature = (string) file_get_contents($uploadedPath, false, null, 0, 4);
        $isZipContainer = in_array($extension, ['xlsx', 'zip'], true);
        $hasZipSignature = str_starts_with($signature, "PK\x03\x04")
            || ($extension === 'zip' && (
                str_starts_with($signature, "PK\x05\x06")
                || str_starts_with($signature, "PK\x07\x08")
            ));
        if ($isZipContainer !== $hasZipSignature) {
            throw new DomainException('La extension del archivo no coincide con su contenido.');
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mediaType = (string) $finfo->file($uploadedPath);
        if ($extension === 'xlsx' && ! in_array($mediaType, [
            'application/zip', 'application/octet-stream',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ], true)) {
            throw new DomainException('El contenido XLSX no es valido.');
        }
        if ($extension === 'zip') {
            if (! in_array($mediaType, [
                'application/zip', 'application/x-zip', 'application/x-zip-compressed', 'application/octet-stream',
            ], true)) {
                throw new DomainException('El contenido ZIP no es valido.');
            }
            $this->assertValidZipPackage($uploadedPath);
        }
        $this->ensurePrivateRoot();
        $destination = rtrim($this->storageRoot, '\\/') . DIRECTORY_SEPARATOR . 'import_' . bin2hex(random_bytes(16)) . '.' . $extension;
        if (! copy($uploadedPath, $destination)) {
            throw new RuntimeException('No se pudo guardar el archivo en almacenamiento privado.');
        }
        @chmod($destination, 0600);
        return new StoredImportFile(
            $destination,
            $safeOriginal,
            $mediaType,
            (int) $size,
            hash_file('sha256', $destination) ?: throw new RuntimeException('No se pudo calcular la huella del archivo.'),
        );
    }

    public function delete(string $privatePath): void
    {
        if ($privatePath === '' || ! is_file($privatePath)) {
            return;
        }
        $this->ensurePrivateRoot();
        if (! $this->isManagedPath($privatePath)) {
            throw new DomainException('Se rechazo eliminar un archivo fuera del almacenamiento de importaciones.');
        }
        if (! unlink($privatePath)) {
            throw new RuntimeException('No se pudo limpiar el archivo temporal de importacion.');
        }
    }

    public function purgeOlderThan(int $retentionDays, array $protectedPaths = []): int
    {
        if ($retentionDays < 1 || ! is_dir($this->storageRoot)) {
            return 0;
        }
        $this->ensurePrivateRoot();
        $deleted = 0;
        $threshold = time() - ($retentionDays * 86400);
        $protected = array_filter(array_map('realpath', $protectedPaths));
        foreach (glob(rtrim($this->storageRoot, '\\/') . DIRECTORY_SEPARATOR . 'import_*') ?: [] as $path) {
            $realPath = realpath($path);
            if ($realPath !== false && in_array($realPath, $protected, true)) {
                continue;
            }
            if (is_file($path) && filemtime($path) !== false && filemtime($path) < $threshold && $this->isManagedPath($path)) {
                if (unlink($path)) {
                    $deleted++;
                }
            }
        }
        return $deleted;
    }

    private function assertValidZipPackage(string $path): void
    {
        if (! class_exists(\ZipArchive::class)) {
            throw new DomainException('No se puede validar el paquete ZIP: falta habilitar la extension PHP zip.');
        }

        $archive = new \ZipArchive();
        $opened = $archive->open($path, \ZipArchive::CHECKCONS);
        if ($opened !== true) {
            throw new DomainException('El paquete ZIP esta dañado o no es un archivo comprimido valido.');
        }

        try {
            $entries = $archive->numFiles;
            if ($entries < 1) {
                throw new DomainException('El paquete ZIP no contiene archivos.');
            }
            if ($entries > self::MAXIMUM_ZIP_ENTRIES) {
                throw new DomainException('El paquete ZIP contiene demasiados archivos.');
            }

            $uncompressedBytes = 0;
            for ($index = 0; $index < $entries; $index++) {
                $name = $archive->getNameIndex($index);
                if (! is_string($name) || ! $this->isSafeZipEntry($name)) {
                    throw new DomainException('El paquete ZIP contiene una ruta de archivo no permitida.');
                }
                $stat = $archive->statIndex($index);
                if ($stat === false) {
                    throw new DomainException('El paquete ZIP contiene una entrada ilegible.');
                }
                $uncompressedBytes += max(0, (int) ($stat['size'] ?? 0));
                if ($uncompressedBytes > $this->maximumZipUncompressedBytes) {
                    throw new DomainException('El contenido del paquete ZIP supera el limite permitido.');
                }
            }
        } finally {
            $archive->close();
        }
    }

    private function isSafeZipEntry(string $name): bool
    {
        if ($name === '' || str_contains($name, "\0")) {
            return false;
        }
        $normalized = str_replace('\\', '/', $name);
        if (str_starts_with($normalized, '/') || preg_match('~^[A-Za-z]:~', $normalized) === 1) {
            return false;
        }
        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '..') {
                return false;
            }
        }
        return true;
    }

    private function isManagedPath(string $path): bool
    {
        $realRoot = realpath($this->storageRoot);
        $realPath = realpath($path);
        return $realRoot !== false && $realPath !== false
            && str_starts_with(str_replace('\\', '/', $realPath), rtrim(str_replace('\\', '/', $realRoot), '/') . '/')
            && str_starts_with(basename($realPath), 'import_');
    }

    private function ensurePrivateRoot(): void
    {
        if (! is_dir($this->storageRoot) && ! mkdir($this->storageRoot, 0700, true) && ! is_dir($this->storageRoot)) {
            throw new RuntimeException('No se pudo crear el almacenamiento privado de importaciones.');
        }
        $realRoot = realpath($this->storageRoot);
        $realProject = defined('ROOTPATH') ? realpath((string) ROOTPATH) : false;
        if ($realRoot === false) {
            throw new RuntimeException('No se pudo resolver el almacenamiento privado de importaciones.');
        }
        if ($realProject !== false) {
            $root = rtrim(str_replace('\\', '/', mb_strtolower($realRoot)), '/');
            $project = rtrim(str_replace('\\', '/', mb_strtolower($realProject)), '/');
            if ($root === $project || str_starts_with($root . '/', $project . '/')) {
                throw new DomainException('La raiz real de importaciones apunta dentro del directorio publico del proyecto.');
            }
        }
    }
}
