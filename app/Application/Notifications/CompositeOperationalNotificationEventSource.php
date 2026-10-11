<?php

declare(strict_types=1);

namespace App\Application\Notifications;

use App\Application\Notifications\Port\OperationalNotificationEventSource;
use App\Domain\Notifications\NotifiableEvent;

/**
 * Junta varias fuentes de eventos en una sola, sin que una fuente vacía o
 * ausente cambie el contrato de `collect()`.
 */
final readonly class CompositeOperationalNotificationEventSource implements OperationalNotificationEventSource
{
    /** @param list<OperationalNotificationEventSource> $sources */
    public function __construct(private array $sources)
    {
    }

    /** @return list<NotifiableEvent> */
    public function collect(): array
    {
        $events = [];

        foreach ($this->sources as $source) {
            foreach ($source->collect() as $event) {
                $events[] = $event;
            }
        }

        return $events;
    }
}