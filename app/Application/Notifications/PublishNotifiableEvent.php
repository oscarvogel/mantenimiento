<?php

declare(strict_types=1);

namespace App\Application\Notifications;

use App\Application\Notifications\Port\CompanyNotificationDeliveryQueue;
use App\Application\Notifications\Port\CompanyNotificationRecipientResolver;
use App\Application\Notifications\Port\NotificationDeliveryQueue;
use App\Application\Notifications\Port\NotificationPreferenceStore;
use App\Application\Notifications\Port\NotificationRecipientResolver;
use App\Application\Notifications\Port\NotificationRepository;
use App\Application\Notifications\Port\NotificationUnitOfWork;
use App\Domain\Notifications\NotifiableEvent;
use App\Domain\Notifications\Notification;

final readonly class PublishNotifiableEvent
{
    public function __construct(
        private NotificationRecipientResolver $recipients,
        private NotificationRepository $notifications,
        private NotificationPreferenceStore $preferences,
        private NotificationDeliveryQueue $deliveries,
        private NotificationUnitOfWork $unitOfWork,
        private CompanyNotificationRecipientResolver $companyRecipients,
        private CompanyNotificationDeliveryQueue $companyDeliveries,
    ) {
    }

    /** @return array{created:int,duplicates:int,recipients:int} */
    public function execute(NotifiableEvent $event): array
    {
        $recipients = $this->recipients->resolve($event);
        $companyRecipient = $this->companyRecipients->resolve($event->companyId());

        return $this->unitOfWork->transactional(function () use ($event, $recipients, $companyRecipient): array {
            $created = 0;
            $duplicates = 0;
            foreach ($recipients as $recipient) {
                if ($recipient->companyId !== $event->companyId()) {
                    continue;
                }
                $notification = Notification::forRecipient($event, $recipient->userId);
                $notificationId = $this->notifications->createIfAbsent($notification);
                if ($notificationId === null) {
                    $duplicates++;
                    continue;
                }
                $preference = $this->preferences->resolve($recipient->userId, $event->type());
                $this->deliveries->schedule($notificationId, $recipient->userId, $event->logicalKey(), $event->severity(), $preference);
                $created++;
            }

            // Este destinatario pertenece a la empresa, no a un usuario. Por eso no
            // hereda preferencias personales ni genera Web Push.
            $this->companyDeliveries->schedule($event, $companyRecipient);

            return ['created' => $created, 'duplicates' => $duplicates, 'recipients' => count($recipients)];
        });
    }
}
