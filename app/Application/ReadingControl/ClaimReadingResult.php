<?php

declare(strict_types=1);

namespace App\Application\ReadingControl;

/**
 * Resultado de un intento de reclamo de lectura por WhatsApp.
 *
 * Es un DTO de salida, no una entidad de dominio. Expone el destino EFECTIVO
 * que se usó, que puede diferir del teléfono real del chofer cuando el modo
 * piloto está activo.
 */
final readonly class ClaimReadingResult
{
    public function __construct(
        public bool $sent,
        public ?int $deliveryId = null,
        public ?string $destinationPhone = null,
        public ?string $messageId = null,
        public ?string $pilotMode = null,
        public ?string $publicUrl = null,
        public ?string $error = null,
    ) {
    }

    public static function sent(
        int $deliveryId,
        string $destinationPhone,
        string $messageId,
        bool $pilotMode,
        string $publicUrl,
    ): self {
        return new self(
            sent: true,
            deliveryId: $deliveryId,
            destinationPhone: $destinationPhone,
            messageId: $messageId,
            pilotMode: $pilotMode ? 'piloto' : 'real',
            publicUrl: $publicUrl,
        );
    }

    public static function blockedByRecentClaim(string $error, ?int $deliveryId = null): self
    {
        return new self(sent: false, deliveryId: $deliveryId, error: $error);
    }
}
