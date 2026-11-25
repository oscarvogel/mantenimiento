<?php

declare(strict_types=1);

namespace App\Infrastructure\CustomerAccounts;

use App\Application\CustomerAccounts\Port\ReceiptFileInspector;
use App\Infrastructure\Assets\Attachment\FileinfoEquipmentAttachmentInspector;

/**
 * Reutiliza la inspección por contenido real (finfo) de los adjuntos del
 * proyecto: nunca se confía en la extensión ni en el MIME declarado por el
 * navegador.
 */
final class FileinfoReceiptFileInspector implements ReceiptFileInspector
{
    public function inspect(string $temporaryPath): array
    {
        $inspected = (new FileinfoEquipmentAttachmentInspector())->inspect($temporaryPath);

        return ['mime_type' => $inspected->mimeType, 'size' => $inspected->size];
    }
}
