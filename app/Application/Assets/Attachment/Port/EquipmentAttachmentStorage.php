<?php

declare(strict_types=1);

namespace App\Application\Assets\Attachment\Port;

use App\Application\Assets\Attachment\StoredAttachmentFile;

interface EquipmentAttachmentStorage
{
    public function store(string $sourcePath, int $companyId, string $extension): StoredAttachmentFile;

    public function read(string $privateRelativePath): string;

    public function delete(string $privateRelativePath): void;
}
