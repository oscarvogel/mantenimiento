<?php

declare(strict_types=1);

namespace App\Infrastructure\WorkOrders\DocumentImport;

use App\Application\WorkOrders\DocumentImport\Port\StoredWorkOrderDocument;
use App\Application\WorkOrders\DocumentImport\Port\WorkOrderDocumentStorage;
use RuntimeException;

final class PrivateWorkOrderDocumentStorage implements WorkOrderDocumentStorage
{
    public function __construct(private readonly string $rootPath = WRITEPATH . 'uploads/work_order_imports') {}

    public function store(string $temporaryPath, int $companyId, string $extension): StoredWorkOrderDocument
    {
        if (! is_file($temporaryPath) || $companyId <= 0) {
            throw new RuntimeException('No se pudo acceder al documento temporal.');
        }
        $extension = strtolower(trim($extension));
        if (! in_array($extension, ['jpg', 'png', 'pdf'], true)) {
            throw new RuntimeException('La extensión del documento no está permitida.');
        }

        $directory = rtrim($this->rootPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $companyId;
        if (! is_dir($directory) && ! mkdir($directory, 0770, true) && ! is_dir($directory)) {
            throw new RuntimeException('No se pudo crear el almacenamiento privado para documentos.');
        }

        $storedName = bin2hex(random_bytes(24)) . '.' . $extension;
        $destination = $directory . DIRECTORY_SEPARATOR . $storedName;
        if (! copy($temporaryPath, $destination)) {
            throw new RuntimeException('No se pudo almacenar el documento.');
        }
        @chmod($destination, 0660);

        return new StoredWorkOrderDocument($storedName, $companyId . '/' . $storedName);
    }

    public function absolutePath(string $privateRelativePath): string
    {
        $relative = str_replace('\\', '/', trim($privateRelativePath));
        if ($relative === '' || str_contains($relative, '..') || str_starts_with($relative, '/')) {
            throw new RuntimeException('La ruta privada del documento no es válida.');
        }
        $path = rtrim($this->rootPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (! is_file($path)) {
            throw new RuntimeException('El documento almacenado no existe.');
        }
        return $path;
    }

    public function delete(string $privateRelativePath): void
    {
        try {
            $path = $this->absolutePath($privateRelativePath);
        } catch (RuntimeException) {
            return;
        }
        if (! unlink($path)) {
            throw new RuntimeException('No se pudo eliminar el documento almacenado.');
        }
    }
}
