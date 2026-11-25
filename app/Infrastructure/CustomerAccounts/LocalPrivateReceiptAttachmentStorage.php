<?php

declare(strict_types=1);

namespace App\Infrastructure\CustomerAccounts;

use App\Application\CustomerAccounts\Port\ReceiptAttachmentStorage;
use App\Infrastructure\Assets\Attachment\LocalPrivateAttachmentStorage;

/**
 * Reutiliza el almacenamiento privado ya auditado del proyecto, en su propia
 * carpeta `recibos`. No expone archivos bajo el webroot y mantiene la misma
 * validación de rutas y permisos que los adjuntos de equipos.
 */
final class LocalPrivateReceiptAttachmentStorage implements ReceiptAttachmentStorage
{
    private readonly LocalPrivateAttachmentStorage $storage;

    public function __construct(?string $root = null)
    {
        $this->storage = new LocalPrivateAttachmentStorage($root, 'recibos');
    }

    public function store(string $sourcePath, int $companyId, string $extension): array
    {
        $stored = $this->storage->store($sourcePath, $companyId, $extension);

        return ['stored_name' => $stored->storedName, 'private_path' => $stored->privateRelativePath];
    }

    public function read(string $privateRelativePath): string
    {
        return $this->storage->read($privateRelativePath);
    }

    public function delete(string $privateRelativePath): void
    {
        $this->storage->delete($privateRelativePath);
    }
}
