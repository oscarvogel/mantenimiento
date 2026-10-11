<?php

declare(strict_types=1);

namespace App\Application\Notifications;

/**
 * Resultado de intentar refrescar el contenido de un aviso ya existente.
 *
 * Distingue tres situaciones que antes se mezclaban en un único
 * `createIfAbsent() === null`:
 */
enum NotificationRefreshOutcome
{
    /** El contenido cambió o el aviso estaba regularizado: vuelve a quedar pendiente. */
    case UPDATED;

    /** El contenido ya era idéntico: no se toca nada, ni estado ni marca de lectura. */
    case UNCHANGED;

    /** No hay aviso para esa clave lógica. */
    case MISSING;
}