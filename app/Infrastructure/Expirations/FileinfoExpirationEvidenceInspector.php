<?php

declare(strict_types=1);

namespace App\Infrastructure\Expirations;

use App\Application\Assets\Attachment\InspectedAttachmentFile;
use App\Application\Expirations\Port\ExpirationEvidenceFileInspector;
use finfo;
use RuntimeException;

final class FileinfoExpirationEvidenceInspector implements ExpirationEvidenceFileInspector
{
    public function inspect(string $temporaryPath): InspectedAttachmentFile
    {
        if ($temporaryPath === '' || ! is_file($temporaryPath) || ! is_readable($temporaryPath)) {
            throw new RuntimeException('No se pudo leer el archivo temporal de la evidencia.');
        }

        $size = filesize($temporaryPath);
        if ($size === false) {
            throw new RuntimeException('No se pudo determinar el tamano real de la evidencia.');
        }

        $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($temporaryPath);
        if ($mimeType === false || trim($mimeType) === '') {
            throw new RuntimeException('No se pudo determinar el tipo real de la evidencia.');
        }

        return new InspectedAttachmentFile(strtolower(trim($mimeType)), (int) $size);
    }
}
