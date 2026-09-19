<?php

declare(strict_types=1);

namespace App\Application\Expirations\Port;

use App\Application\Assets\Attachment\DownloadedEquipmentAttachment;

interface ExpirationEvidenceDownload
{
    /**
     * Devuelve el archivo + metadatos para que el controller lo sirva. Si la
     * evidencia no existe o pertenece a otra empresa, devuelve null y el
     * controller responde 404.
     */
    public function download(int $companyId, int $evidenceId): ?DownloadedEquipmentAttachment;
}
