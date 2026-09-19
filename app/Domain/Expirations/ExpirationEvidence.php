<?php

declare(strict_types=1);

namespace App\Domain\Expirations;

use DateTimeImmutable;
use DomainException;

/**
 * Adjunto (imagen o PDF) asociado a UNA renovacion de vencimiento.
 *
 * El dominio valida que la extension y el mime coincidan con el set
 * permitido por el sistema (pdf/jpg/png/webp) para evitar que un atacante
 * suba un .exe haciendolo pasar por un PDF. Las reglas detalladas viven en
 * el caso de uso; esta clase protege los invariantes geometricos.
 */
final class ExpirationEvidence
{
    private const ALLOWED_MIME_EXTENSIONS = [
        'application/pdf' => ['pdf'],
        'image/jpeg'      => ['jpg', 'jpeg'],
        'image/png'       => ['png'],
        'image/webp'      => ['webp'],
    ];

    public function __construct(
        public readonly int $companyId,
        public readonly int $expirationId,
        public readonly string $originalName,
        public readonly string $storedName,
        public readonly string $privateRelativePath,
        public readonly string $mimeType,
        public readonly int $size,
        public readonly int $createdBy,
        public readonly DateTimeImmutable $createdAt,
        public readonly ?int $id = null,
    ) {
        if ($companyId <= 0) {
            throw new DomainException('La empresa de la evidencia debe ser valida.');
        }
        if ($expirationId <= 0) {
            throw new DomainException('El vencimiento asociado a la evidencia debe ser valido.');
        }
        if ($createdBy <= 0) {
            throw new DomainException('El usuario que registro la evidencia debe ser valido.');
        }

        $originalName = trim($originalName);
        if ($originalName === '' || strlen($originalName) > 255
            || preg_match('/[\x00-\x1F\x7F]/', $originalName)
            || str_contains($originalName, '/')
            || str_contains($originalName, '\\')) {
            throw new DomainException('El nombre original de la evidencia no es valido.');
        }
        if ($size <= 0) {
            throw new DomainException('La evidencia esta vacia.');
        }

        $mimeType = strtolower(trim($mimeType));
        $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
        if (! isset(self::ALLOWED_MIME_EXTENSIONS[$mimeType])
            || ! in_array($extension, self::ALLOWED_MIME_EXTENSIONS[$mimeType], true)) {
            throw new DomainException('El tipo real y la extension de la evidencia no estan permitidos.');
        }

        $expectedExtension = self::ALLOWED_MIME_EXTENSIONS[$mimeType][0];
        if (! preg_match('/^[a-f0-9]{48}\.' . preg_quote($expectedExtension, '/') . '$/', $storedName)) {
            throw new DomainException('El nombre almacenado de la evidencia no es opaco o seguro.');
        }
        if ($privateRelativePath !== $companyId . '/' . $storedName) {
            throw new DomainException('La ruta privada de la evidencia no pertenece a la empresa indicada.');
        }
    }
}
