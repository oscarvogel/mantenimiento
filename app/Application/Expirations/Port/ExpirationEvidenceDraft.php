<?php

declare(strict_types=1);

namespace App\Application\Expirations\Port;

/**
 * Estructura minima que el caso de uso de renovacion pasa al puerto de
 * evidencia para que el adaptador (CodeIgniter + LocalPrivateExpiration
 * EvidenceStorage) se ocupe del IO y la persistencia.
 */
final class ExpirationEvidenceDraft
{
    public function __construct(
        public readonly int $companyId,
        public readonly int $expirationId,
        public readonly string $temporaryPath,
        public readonly string $originalName,
        public readonly string $mimeType,
        public readonly int $size,
        public readonly int $createdBy,
    ) {
    }
}
