<?php

declare(strict_types=1);

namespace App\Application\Notifications\Port;

use App\Domain\Notifications\NotifiableEvent;

interface CompanyNotificationDeliveryQueue
{
    public function scheduleCompany(NotifiableEvent $event): void;

    /** @return list<array<string,mixed>> */
    public function dueCompany(int $limit): array;

    public function deliveredCompany(int $deliveryId): void;

    /**
     * Descarta una entrega que ya no corresponde enviar porque otra entrega del
     * mismo bucket gerencial la reemplaza. Un informe gerencial es una foto del
     * estado actual: enviar una fila vieja solo confunde al destinatario.
     */
    public function skippedCompany(int $deliveryId, string $reason): void;

    public function failedCompany(int $deliveryId, string $error, bool $retryable): void;
}
