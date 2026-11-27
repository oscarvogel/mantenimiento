<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications;

use App\Application\Employees\Port\PendingDriverNotifications;
use App\Domain\Notifications\DriverNotificationTypes;
use CodeIgniter\Database\BaseConnection;

/**
 * Cancela las entregas de WhatsApp que todavía no salieron y que dependen de la
 * asignación del chofer que se está desvinculando.
 *
 * Las que ya fueron aceptadas por el gateway (`ACEPTADA`,
 * `PENDIENTE_CONFIRMACION`) no se tocan: el mensaje ya salió. Tampoco se tocan
 * las notificaciones internas de los responsables ni los resúmenes de usuarios.
 */
final class CodeIgniterPendingDriverNotifications implements PendingDriverNotifications
{
    public function __construct(private readonly BaseConnection $database)
    {
    }

    public function cancelForAssignment(
        int $companyId,
        int $equipmentId,
        int $employeeId,
        int $assignmentId,
        string $reason,
    ): int {
        if ($companyId <= 0 || $equipmentId <= 0 || $employeeId <= 0) {
            return 0;
        }

        $reason = trim(preg_replace('/\s+/u', ' ', $reason) ?? '');
        $message = 'Cancelada por desvinculación del chofer (asignación #' . $assignmentId . ')'
            . ($reason === '' ? '.' : ': ' . $reason);

        $this->database->table('notificacion_whatsapp_entregas')
            ->where('empresa_id', $companyId)
            ->where('equipo_id', $equipmentId)
            ->where('empleado_id', $employeeId)
            ->whereIn('tipo_evento', DriverNotificationTypes::all())
            ->whereIn('estado', ['PENDIENTE', 'REINTENTO'])
            ->update([
                'estado' => 'OMITIDA',
                'ultimo_error' => mb_substr($message, 0, 1000),
                'proximo_intento' => null,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

        return max(0, $this->database->affectedRows());
    }
}
