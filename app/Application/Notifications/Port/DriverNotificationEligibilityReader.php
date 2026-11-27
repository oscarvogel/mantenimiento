<?php

declare(strict_types=1);

namespace App\Application\Notifications\Port;

use App\Domain\Notifications\DriverNotificationEligibility;

/**
 * Predicado único de elegibilidad de destinatarios chofer.
 *
 * Lo usan la generación de entregas y la revalidación previa al envío. Toda
 * consulta que decida si un chofer puede recibir un mensaje de una unidad debe
 * pasar por este puerto: no se admite una copia local del criterio.
 */
interface DriverNotificationEligibilityReader
{
    /**
     * Evalúa la asignación del empleado indicado para el equipo indicado.
     *
     * @param string $today Día calendario en formato Y-m-d, en la zona horaria de la empresa.
     */
    public function evaluate(
        int $companyId,
        int $equipmentId,
        int $employeeId,
        string $today,
    ): DriverNotificationEligibility;

    /**
     * Devuelve el empleado que hoy es el chofer elegible del equipo, o null.
     *
     * Considera la asignación más reciente ya iniciada. Si esa asignación no
     * pasa el predicado, el equipo no tiene chofer elegible: no se retrocede a
     * una asignación anterior.
     */
    public function findCurrentDriver(
        int $companyId,
        int $equipmentId,
        string $today,
    ): ?int;
}
