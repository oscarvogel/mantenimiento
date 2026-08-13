<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications;

use App\Application\Notifications\Port\WebPushGateway;
use App\Application\Notifications\Port\WebPushSubscriptionStore;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use RuntimeException;

final readonly class MinishlinkWebPushGateway implements WebPushGateway
{
    public function __construct(
        private WebPushSubscriptionStore $subscriptions,
        private bool $enabled,
        private string $subject,
        private string $publicKey,
        private string $privateKey,
    ) {
    }

    public function sendToUser(int $userId, string $title, string $summary, ?string $url): array
    {
        if (! $this->enabled) {
            throw new RuntimeException('Web Push está desactivado.');
        }
        if ($this->subject === '' || $this->publicKey === '' || $this->privateKey === '') {
            throw new RuntimeException('Las claves VAPID no están configuradas.');
        }

        $webPush = new WebPush(['VAPID' => ['subject' => $this->subject, 'publicKey' => $this->publicKey, 'privateKey' => $this->privateKey]]);
        $payload = json_encode(['title' => $title, 'body' => $summary, 'url' => $url ?? '/dashboard'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        foreach ($this->subscriptions->activeForUser($userId) as $row) {
            $webPush->queueNotification(Subscription::create([
                'endpoint' => $row['endpoint'],
                'publicKey' => $row['p256dh'],
                'authToken' => $row['auth'],
                'contentEncoding' => $row['content_encoding'],
            ]), $payload, ['TTL' => 3600, 'urgency' => 'normal']);
        }

        $result = ['sent' => 0, 'expired' => 0, 'failed' => 0];
        foreach ($webPush->flush() as $report) {
            if ($report->isSuccess()) {
                $result['sent']++;
            } elseif ($report->isSubscriptionExpired()) {
                $result['expired']++;
                $this->subscriptions->markInvalid($report->getEndpoint(), $report->getReason());
            } else {
                $result['failed']++;
            }
        }
        return $result;
    }
}
