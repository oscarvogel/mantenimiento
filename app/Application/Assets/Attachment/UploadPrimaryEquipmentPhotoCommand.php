<?php

declare(strict_types=1);

namespace App\Application\Assets\Attachment;

final readonly class UploadPrimaryEquipmentPhotoCommand
{
    public function __construct(
        public int $equipmentId,
        public string $temporaryPath,
        public string $originalName,
        public ?string $description = null,
    ) {
    }
}
