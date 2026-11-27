<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

/**
 * Tipos de entrega cuyo destinatario es el chofer de una unidad.
 *
 * Es la lista única que comparten la cancelación por desvinculación, la
 * generación de eventos y la revalidación antes del despacho. Si un tipo nuevo
 * se envía al chofer, debe declararse acá una sola vez.
 */
final class DriverNotificationTypes
{
    public const WEEKLY_READING_REMINDER = 'equipo.recordatorio_lectura_semanal';
    public const MANUAL_READING_CLAIM = 'equipo.reclamo_manual_lectura';
    public const EXPIRATION_DIGEST = 'equipo.resumen_vencimientos';
    public const PREVENTIVE_DUE = 'preventivo.proximo';
    public const PREVENTIVE_OVERDUE = 'preventivo.vencido';
    public const READING_RECEIPT = 'equipo.lectura_registrada';

    /** @var list<string> */
    private const TYPES = [
        self::WEEKLY_READING_REMINDER,
        self::MANUAL_READING_CLAIM,
        self::EXPIRATION_DIGEST,
        self::PREVENTIVE_DUE,
        self::PREVENTIVE_OVERDUE,
        self::READING_RECEIPT,
    ];

    /** @return list<string> */
    public static function all(): array
    {
        return self::TYPES;
    }

    public static function targetsDriver(string $eventType): bool
    {
        return in_array(trim($eventType), self::TYPES, true);
    }
}
