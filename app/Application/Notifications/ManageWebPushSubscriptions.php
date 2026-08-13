<?php

declare(strict_types=1);

namespace App\Application\Notifications;

use App\Application\Identity\ActorContext;
use App\Application\Notifications\Port\WebPushSubscriptionStore;
use App\Domain\Notifications\WebPushSubscription;

final readonly class ManageWebPushSubscriptions
{
    public function __construct(private WebPushSubscriptionStore $subscriptions)
    {
    }

    public function subscribe(ActorContext $actor, string $endpoint, string $p256dh, string $auth, ?string $deviceName, ?string $userAgent): int
    {
        $this->assertTenantActor($actor);
        return $this->subscriptions->upsert(new WebPushSubscription(
            $actor->userId(),
            $endpoint,
            $p256dh,
            $auth,
            $deviceName,
            $userAgent,
        ));
    }

    public function unsubscribe(ActorContext $actor, string $endpoint): void
    {
        $this->assertTenantActor($actor);
        if (! $this->subscriptions->deactivate($actor->userId(), $endpoint)) {
            throw new \DomainException('La suscripción no existe o pertenece a otro usuario.');
        }
    }

    private function assertTenantActor(ActorContext $actor): void
    {
        if ($actor->isSuperAdmin() || $actor->companyId() === null || ! $actor->hasPermission('notificaciones.ver')) {
            throw new \DomainException('Web Push operativo requiere un usuario de empresa.');
        }
    }
}
