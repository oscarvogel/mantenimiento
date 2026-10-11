<?php

declare(strict_types=1);

namespace App\Application\Notifications\Port;

use App\Domain\Notifications\NotifiableEvent;

interface NotifiableEventPublisher
{
    public function publish(NotifiableEvent $event): void;

    /**
     * Igual que `publish`, pero informa cuántas notificaciones se crearon y
     * cuántas eran repeticiones. Necesario para poderle decir al operador
     * "2 alertas nuevas" en vez de un genérico "listo".
     *
     * @return array{created:int, duplicates:int}
     */
    public function execute(NotifiableEvent $event): array;
}
