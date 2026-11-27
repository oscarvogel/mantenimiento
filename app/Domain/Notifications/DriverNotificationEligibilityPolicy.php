<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

/**
 * Predicado único de elegibilidad de destinatarios chofer.
 *
 * Se usa igual al generar una entrega y al despacharla. La comparación de
 * fechas es por día calendario: una asignación que termina hoy todavía es
 * válida hoy, y una que empieza mañana todavía no lo es.
 */
final class DriverNotificationEligibilityPolicy
{
    public function evaluate(?DriverAssignmentFacts $facts, string $today): DriverNotificationEligibility
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $today) !== 1) {
            throw new \InvalidArgumentException('La fecha de evaluación debe tener formato Y-m-d.');
        }

        if ($facts === null) {
            return DriverNotificationEligibility::blocked(
                DriverNotificationEligibility::CHOFER_SIN_ASIGNACION,
                'El chofer ya no está asignado a la unidad.',
            );
        }

        if ($facts->startsOn > $today) {
            return DriverNotificationEligibility::blocked(
                DriverNotificationEligibility::ASIGNACION_NO_INICIADA,
                'La asignación del chofer todavía no comenzó.',
            );
        }

        if ($facts->endsOn !== null && $facts->endsOn < $today) {
            return DriverNotificationEligibility::blocked(
                DriverNotificationEligibility::ASIGNACION_CERRADA,
                'La asignación del chofer está cerrada.',
            );
        }

        if ($facts->employeeDeleted) {
            return DriverNotificationEligibility::blocked(
                DriverNotificationEligibility::EMPLEADO_ELIMINADO,
                'El empleado está dado de baja del sistema.',
            );
        }

        if (! $facts->employeeActive) {
            return DriverNotificationEligibility::blocked(
                DriverNotificationEligibility::EMPLEADO_INACTIVO,
                'El empleado está inactivo.',
            );
        }

        if ($facts->equipmentDeleted) {
            return DriverNotificationEligibility::blocked(
                DriverNotificationEligibility::EQUIPO_ELIMINADO,
                'El equipo está dado de baja del sistema.',
            );
        }

        if (mb_strtoupper(trim($facts->equipmentState)) !== 'ACTIVO') {
            return DriverNotificationEligibility::blocked(
                DriverNotificationEligibility::EQUIPO_INACTIVO,
                'El equipo no está activo.',
            );
        }

        if (! $facts->typeControlsKilometers) {
            return DriverNotificationEligibility::blocked(
                DriverNotificationEligibility::TIPO_SIN_KILOMETRAJE,
                'El tipo de equipo no administra kilometraje.',
            );
        }

        if ($facts->companyDeleted) {
            return DriverNotificationEligibility::blocked(
                DriverNotificationEligibility::EMPRESA_ELIMINADA,
                'La empresa está dada de baja del sistema.',
            );
        }

        if (! $facts->companyActive) {
            return DriverNotificationEligibility::blocked(
                DriverNotificationEligibility::EMPRESA_INACTIVA,
                'La empresa está inactiva.',
            );
        }

        if (! $facts->companyWhatsAppEnabled) {
            return DriverNotificationEligibility::blocked(
                DriverNotificationEligibility::WHATSAPP_EMPRESA_DESHABILITADO,
                'La empresa no tiene habilitadas las notificaciones por WhatsApp.',
            );
        }

        return DriverNotificationEligibility::eligible();
    }
}
