<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications;

use App\Application\Notifications\Port\WebPushSubscriptionStore;
use App\Application\Notifications\Port\NotificationClock;
use App\Domain\Notifications\WebPushSubscription;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

final class CodeIgniterWebPushSubscriptionStore implements WebPushSubscriptionStore
{
    public function __construct(private NotificationClock $clock, private ?BaseConnection $db = null)
    {
        $this->db ??= Database::connect();
    }

    public function upsert(WebPushSubscription $subscription): int
    {
        $existing = $this->db->table('webpush_subscriptions')->select('id')->where('usuario_id', $subscription->userId)->where('endpoint_hash', $subscription->endpointHash())->get()->getRowArray();
        $data = [
            'endpoint' => $subscription->endpoint,
            'p256dh' => $subscription->p256dh,
            'auth' => $subscription->auth,
            'content_encoding' => $subscription->contentEncoding,
            'user_agent' => $subscription->userAgent === null ? null : mb_substr($subscription->userAgent, 0, 500),
            'nombre_dispositivo' => $subscription->deviceName,
            'ultimo_uso' => $this->clock->now()->format('Y-m-d H:i:s'),
            'activo' => 1,
            'fecha_baja' => null,
            'ultimo_error' => null,
        ];
        if ($existing === null) {
            $this->db->table('webpush_subscriptions')->insert($data + [
                'usuario_id' => $subscription->userId,
                'endpoint_hash' => $subscription->endpointHash(),
                'fecha_alta' => $this->clock->now()->format('Y-m-d H:i:s'),
            ]);
            return (int) $this->db->insertID();
        }
        $this->db->table('webpush_subscriptions')->where('id', $existing['id'])->update($data);
        return (int) $existing['id'];
    }

    public function deactivate(int $userId, string $endpoint): bool
    {
        $builder = $this->db->table('webpush_subscriptions')->where('usuario_id', $userId)->where('endpoint_hash', hash('sha256', $endpoint))->where('activo', 1);
        $exists = $builder->countAllResults(false) > 0;
        if ($exists) {
            $builder->update(['activo' => 0, 'fecha_baja' => $this->clock->now()->format('Y-m-d H:i:s')]);
        }
        return $exists;
    }

    public function activeForUser(int $userId): array
    {
        return $this->db->table('webpush_subscriptions')->select('endpoint, p256dh, auth, content_encoding')->where('usuario_id', $userId)->where('activo', 1)->get()->getResultArray();
    }

    public function markDelivered(string $endpoint): void
    {
        $this->db->table('webpush_subscriptions')->where('endpoint_hash', hash('sha256', $endpoint))->where('activo', 1)->update([
            'ultimo_uso' => $this->clock->now()->format('Y-m-d H:i:s'),
            'ultimo_error' => null,
        ]);
    }

    public function markFailed(string $endpoint, string $error): void
    {
        $this->db->table('webpush_subscriptions')->where('endpoint_hash', hash('sha256', $endpoint))->where('activo', 1)->update([
            'ultimo_error' => mb_substr($error, 0, 1000),
        ]);
    }

    public function markInvalid(string $endpoint, string $error): void
    {
        $this->db->table('webpush_subscriptions')->where('endpoint_hash', hash('sha256', $endpoint))->update([
            'activo' => 0, 'fecha_baja' => $this->clock->now()->format('Y-m-d H:i:s'), 'ultimo_error' => mb_substr($error, 0, 1000),
        ]);
    }
}
