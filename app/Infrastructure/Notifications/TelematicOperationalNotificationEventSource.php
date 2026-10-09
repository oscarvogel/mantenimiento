<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications;

use App\Application\Notifications\Port\OperationalNotificationEventSource;
use App\Application\Telematic\DiagnoseSilentUnits;
use App\Domain\Notifications\NotifiableEvent;

/**
 * Publica la alerta de telemetría estancada dentro del ciclo de notificaciones.
 *
 * Aísla los fallos a propósito: si el proveedor de telemetría no responde,
 * esa fuente devuelve vacío y el resto del ciclo -preventivos, órdenes,
 * vencimientos- sigue publicando con normalidad. Un proveedor caído nunca
 * puede dejar al cliente sin alertas de su trabajo.
 */
final class TelematicOperationalNotificationEventSource implements OperationalNotificationEventSource
{
    public function __construct(private readonly DiagnoseSilentUnits $diagnose)
    {
    }

    /** @return list<NotifiableEvent> */
    public function collect(): array
    {
        try {
            return $this->diagnose->execute();
        } catch (\Throwable $exception) {
            log_message('error', 'No se pudo evaluar la telemetría de la flota: {message}', [
                'message' => $exception->getMessage(),
            ]);

            return [];
        }
    }
}