<?php

declare(strict_types=1);

namespace App\Infrastructure\Assets\Attachment;

use App\Application\Assets\Attachment\InspectedAttachmentFile;
use App\Application\Assets\Attachment\Port\EquipmentAttachmentFileInspector;
use finfo;
use RuntimeException;

final class FileinfoEquipmentAttachmentInspector implements EquipmentAttachmentFileInspector
{
    public function inspect(string $temporaryPath): InspectedAttachmentFile
    {
        if ($temporaryPath === '' || ! is_file($temporaryPath) || ! is_readable($temporaryPath)) {
            throw new RuntimeException('No se pudo leer el archivo temporal del adjunto.');
        }

        $size = filesize($temporaryPath);
        if ($size === false) {
            throw new RuntimeException('No se pudo determinar el tamaño real del adjunto.');
        }

        $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($temporaryPath);
        if ($mimeType === false || trim($mimeType) === '') {
            throw new RuntimeException('No se pudo determinar el tipo real del adjunto.');
        }

        return new InspectedAttachmentFile(strtolower(trim($mimeType)), (int) $size);
    }
}
