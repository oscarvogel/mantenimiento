<?php

declare(strict_types=1);

namespace App\Domain\CustomerAccounts;

use DomainException;

/**
 * Reglas de los comprobantes adjuntos de un recibo: solo PDF o imagen, tamaño
 * acotado y nombre original sin rutas. El tipo real lo determina el inspector
 * antes de llegar acá; nunca se confía en la extensión informada.
 */
final class ReceiptAttachmentRules
{
    public const MAXIMUM_BYTES = 10_485_760;

    /** @var array<string, list<string>> */
    private const ALLOWED = [
        'application/pdf' => ['pdf'],
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/webp' => ['webp'],
    ];

    public static function assertUpload(string $originalName, string $mimeType, int $size): void
    {
        $originalName = trim($originalName);
        if ($originalName === '' || strlen($originalName) > 255 || preg_match('/[\x00-\x1F\x7F]/', $originalName)) {
            throw new DomainException('El nombre del comprobante no es válido.');
        }
        if (str_contains($originalName, '/') || str_contains($originalName, '\\')) {
            throw new DomainException('El nombre del comprobante no puede contener una ruta.');
        }
        if ($size <= 0 || $size > self::MAXIMUM_BYTES) {
            throw new DomainException('El comprobante está vacío o supera el tamaño máximo de 10 MB.');
        }
        $mimeType = strtolower(trim($mimeType));
        $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
        if (trim((string) pathinfo($originalName, PATHINFO_FILENAME)) === ''
            || ! isset(self::ALLOWED[$mimeType])
            || ! in_array($extension, self::ALLOWED[$mimeType], true)) {
            throw new DomainException('El tipo real y la extensión del comprobante no están permitidos. Adjuntá PDF, JPG, PNG o WEBP.');
        }
    }

    public static function canonicalExtension(string $mimeType): string
    {
        $mimeType = strtolower(trim($mimeType));
        if (! isset(self::ALLOWED[$mimeType])) {
            throw new DomainException('El tipo real del comprobante no está permitido.');
        }

        return self::ALLOWED[$mimeType][0];
    }
}
