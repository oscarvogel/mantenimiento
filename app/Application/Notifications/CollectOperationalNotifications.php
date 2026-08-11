<?php

declare(strict_types=1);

namespace App\Application\Notifications;

use App\Application\Notifications\Port\OperationalNotificationEventSource;

final readonly class CollectOperationalNotifications
{
    public function __construct(private OperationalNotificationEventSource $source, private PublishNotifiableEvent $publisher)
    {
    }

    /** @return array{events:int,created:int,duplicates:int} */
    public function execute(): array
    {
        $summary = ['events' => 0, 'created' => 0, 'duplicates' => 0];
        foreach ($this->source->collect() as $event) {
            $result = $this->publisher->execute($event);
            $summary['events']++;
            $summary['created'] += $result['created'];
            $summary['duplicates'] += $result['duplicates'];
        }
        return $summary;
    }
}
