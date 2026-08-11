<?php

declare(strict_types=1);

namespace App\Application\Notifications;

use App\Application\Identity\ActorContext;
use App\Application\Notifications\Port\NotificationPreferenceStore;
use App\Domain\Notifications\DeliveryMode;
use App\Domain\Notifications\NotificationPreference;

final readonly class ManageNotificationPreferences
{
    public function __construct(private NotificationPreferenceStore $preferences)
    {
    }

    /** @return array<string,NotificationPreference> */
    public function list(ActorContext $actor): array
    {
        $this->assertTenantActor($actor);
        return $this->preferences->allForUser($actor->userId());
    }

    public function update(ActorContext $actor, string $eventType, string $internal, string $email, string $push): void
    {
        $this->assertTenantActor($actor);
        if (preg_match('/^[a-z0-9_.-]{3,80}$/', $eventType) !== 1) {
            throw new \DomainException('El tipo de evento no es válido.');
        }

        $preference = new NotificationPreference(
            $this->mode($internal),
            $this->mode($email),
            $this->mode($push),
        );
        $this->preferences->save($actor->userId(), $eventType, $preference);
    }

    private function mode(string $value): DeliveryMode
    {
        return DeliveryMode::tryFrom($value) ?? throw new \DomainException('El modo de entrega no es válido.');
    }

    private function assertTenantActor(ActorContext $actor): void
    {
        if ($actor->isSuperAdmin() || $actor->companyId() === null || ! $actor->hasPermission('notificaciones.ver')) {
            throw new \DomainException('Las preferencias operativas requieren un usuario de empresa.');
        }
    }
}
