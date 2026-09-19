<?php

declare(strict_types=1);

namespace App\Application\Expirations\Port;

use App\Application\Assets\Attachment\StoredAttachmentFile;

interface ExpirationEvidenceStorage
{
    /**
     * Mueve el archivo temporal al almacenamiento privado de la empresa y
     * devuelve la ruta relativa + nombre almacenado para la tabla
     * vencimiento_evidencias. La implementacion rechaza extensiones fuera de
     * pdf/jpg/png/webp para mantener consistencia con la pieza de evidencia
     * en OT y en equipos.
     */
    public function store(string $temporaryPath, int $companyId, string $extension): StoredAttachmentFile;

    public function read(string $privateRelativePath): string;

    public function delete(string $privateRelativePath): void;
}
