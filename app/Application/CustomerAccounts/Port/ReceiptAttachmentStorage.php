<?php

declare(strict_types=1);

namespace App\Application\CustomerAccounts\Port;

/** Almacenamiento privado de comprobantes de cobranza, fuera del webroot. */
interface ReceiptAttachmentStorage
{
    /** @return array{stored_name:string,private_path:string} */
    public function store(string $sourcePath, int $companyId, string $extension): array;

    public function read(string $privateRelativePath): string;

    public function delete(string $privateRelativePath): void;
}
