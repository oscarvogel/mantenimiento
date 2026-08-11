<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

final readonly class NotificationPreference
{
    public function __construct(
        public DeliveryMode $internal,
        public DeliveryMode $email,
        public DeliveryMode $push,
    ) {
        if ($internal !== DeliveryMode::IMMEDIATE) {
            throw new \DomainException('La notificación interna es la fuente persistente y no puede desactivarse ni diferirse.');
        }
    }

    public static function defaults(): self
    {
        return new self(DeliveryMode::IMMEDIATE, DeliveryMode::DAILY_DIGEST, DeliveryMode::CRITICAL_ONLY);
    }
}
