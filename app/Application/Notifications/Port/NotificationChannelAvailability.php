<?php

declare(strict_types=1);

namespace App\Application\Notifications\Port;

use App\Domain\Notifications\NotificationChannel;

/**
 * Disponibilidad efectiva de los canales de notificación.
 *
 * La consulta es global: no depende del usuario ni de la empresa. Sirve para
 * que ni las preferencias ni el despacho intente usar un canal que el
 * administrador dejó deshabilitado.
 */
interface NotificationChannelAvailability
{
    public function isAvailable(NotificationChannel $channel): bool;

    /**
     * Motivo por el que el canal no está disponible.
     *
     * Se registra junto al evento para que quede explícito por qué no se
     * envió nada, en lugar de un fallo genérico.
     */
    public function unavailableReason(NotificationChannel $channel): string;
}
