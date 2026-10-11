<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

/**
 * Estados posibles de una notificación del centro operativo.
 *
 * La distinción importa porque conviven dos cierres distintos:
 *
 * - `LEIDA` significa que una persona abrió la notificación.
 * - `REGULARIZADA` significa que el sistema verificó que la condición que
 *   originó el aviso ya no se cumple (por ejemplo, el teléfono del chofer
 *   fue corregido). No hubo lectura humana: el contenido original se conserva
 *   como auditoría y `leida_en` pasa a marcar la salida de la bandeja, igual
 *   que en `WeeklyReadingNotificationRevalidator`.
 *
 * Antes de #473 la regularización automática reutilizaba `LEIDA`, lo que
 * hacía imposible distinguir "alguien lo leyó" de "ya no hay nada que
 * corregir". Por eso existe un estado propio.
 *
 * El esquema ya lo admite: `notificaciones.estado` es VARCHAR(20) sin
 * restricción y `REGULARIZADA` entra holgada, así que no hace falta migración.
 */
enum NotificationState: string
{
    case PENDING = 'PENDIENTE';
    case READ = 'LEIDA';
    case REGULARIZED = 'REGULARIZADA';

    /** Sólo los avisos pendientes siguen contando como no leídos. */
    public function isPending(): bool
    {
        return $this === self::PENDING;
    }
}