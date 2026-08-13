<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use DateTimeImmutable;
use InvalidArgumentException;

final class Notification
{
    private ?DateTimeImmutable $readAt = null;

    private function __construct(
        private readonly ?int $id,
        private readonly int $recipientUserId,
        private readonly NotifiableEvent $event,
    ) {
        if ($recipientUserId <= 0) {
            throw new InvalidArgumentException('El destinatario de la notificación debe ser válido.');
        }
    }

    public static function forRecipient(NotifiableEvent $event, int $recipientUserId): self
    {
        return new self(null, $recipientUserId, $event);
    }

    public function markRead(DateTimeImmutable $at): void
    {
        $this->readAt ??= $at;
    }

    public function id(): ?int { return $this->id; }
    public function recipientUserId(): int { return $this->recipientUserId; }
    public function event(): NotifiableEvent { return $this->event; }
    public function readAt(): ?DateTimeImmutable { return $this->readAt; }
    public function idempotencyKey(): string { return $this->event->logicalKey(); }
}
