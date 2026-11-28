<?php

declare(strict_types=1);

namespace App\Application\Notifications\Port;

use App\Domain\Notifications\NotificationPreference;
use App\Domain\Notifications\NotificationSeverity;

interface NotificationDeliveryQueue
{
    /**
     * @param string|null $deliveryEmail correo de entrega ya resuelto; null conserva
     *                                  el comportamiento histórico y se resuelve en `due()`
     */
    public function schedule(int $notificationId, int $userId, string $eventKey, NotificationSeverity $severity, NotificationPreference $preference, ?string $deliveryEmail = null): void;

    /** @return list<array{id:int,notification_id:int,usuario_id:int,sucursal_id?:int,email:string,canal:string,titulo:string,resumen:string,url:?string,severidad:string,intentos:int}> */
    public function due(string $channel, int $limit): array;

    public function delivered(int $deliveryId): void;

    public function skipped(int $deliveryId, string $reason): void;

    /**
     * Anota por qué una entrega no se intentó sin cerrar ni fallar.
     *
     * Se usa cuando el canal está deshabilitado: la entrega queda en espera y
     * se envía cuando el canal vuelva a estar disponible. Perderla o marcarla
     * como fallida serían losses silenciosos o fallas falsas.
     */
    public function deferred(int $deliveryId, string $reason): void;

    public function failed(int $deliveryId, string $error, bool $retryable): void;
}
