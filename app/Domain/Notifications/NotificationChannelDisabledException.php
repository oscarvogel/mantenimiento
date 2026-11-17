<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use DomainException;

/**
 * El canal está deshabilitado por el administrador del sistema.
 *
 * No es una falla de envío: es una decisión explícita de configuración. El
 * motor de notificaciones tiene que distinguirla de un error de SMTP para no
 * contar reintentos ni marcar el ciclo como fallido (#146).
 */
final class NotificationChannelDisabledException extends DomainException
{
    public static function forChannel(NotificationChannel $channel, string $reason): self
    {
        return new self(sprintf('El canal %s está deshabilitado: %s', $channel->value, $reason));
    }
}
