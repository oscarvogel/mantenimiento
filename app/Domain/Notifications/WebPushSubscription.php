<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use InvalidArgumentException;

final readonly class WebPushSubscription
{
    public function __construct(
        public int $userId,
        public string $endpoint,
        public string $p256dh,
        public string $auth,
        public ?string $deviceName = null,
        public ?string $userAgent = null,
        public string $contentEncoding = 'aes128gcm',
    ) {
        if ($userId <= 0 || filter_var($endpoint, FILTER_VALIDATE_URL) === false || ! str_starts_with($endpoint, 'https://')) {
            throw new InvalidArgumentException('La suscripción Web Push no es válida.');
        }
        if (trim($p256dh) === '' || trim($auth) === '') {
            throw new InvalidArgumentException('La suscripción Web Push requiere sus claves públicas.');
        }
        if (strlen($p256dh) > 255 || strlen($auth) > 255 || ($deviceName !== null && mb_strlen($deviceName) > 100)) {
            throw new InvalidArgumentException('La suscripción Web Push excede los límites permitidos.');
        }
    }

    public function endpointHash(): string
    {
        return hash('sha256', $this->endpoint);
    }
}
