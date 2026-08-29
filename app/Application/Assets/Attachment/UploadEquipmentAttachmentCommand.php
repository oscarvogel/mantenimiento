<?php

declare(strict_types=1);

namespace App\Application\Assets\Attachment;

final readonly class UploadEquipmentAttachmentCommand
{
    public function __construct(
        public int $equipmentId,
        public string $temporaryPath,
        public string $originalName,
        public string $type,
        public ?string $description = null,
        public string $requiredPermission = 'equipos.editar',
    ) {
    }
}
