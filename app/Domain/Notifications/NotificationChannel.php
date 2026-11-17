<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

/**
 * Canales de entrega de las notificaciones operativas.
 *
 * La disponibilidad de cada canal es global: la configuración del sistema la
 * fija el SuperAdministrador. Una preferencia individual nunca puede habilitar
 * un canal que el administrador dejó deshabilitado (#146).
 */
enum NotificationChannel: string
{
    case EMAIL = 'EMAIL';
    case PUSH = 'PUSH';
    case WHATSAPP = 'WHATSAPP';
}
