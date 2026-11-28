<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications;

use App\Application\Notifications\NotificationRecipient;
use App\Application\Notifications\NotificationRecipientScopePolicy;
use App\Application\Notifications\Port\NotificationRecipientResolver;
use App\Application\Notifications\UserNotificationEmailPolicy;
use App\Domain\Notifications\NotifiableEvent;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

final class CodeIgniterNotificationRecipientResolver implements NotificationRecipientResolver
{
    public function __construct(
        private NotificationRecipientScopePolicy $scope,
        private UserNotificationEmailPolicy $emails,
        private ?BaseConnection $db = null,
    ) {
        $this->db ??= Database::connect();
    }

    public function resolve(NotifiableEvent $event): array
    {
        $permission = $this->permissionFor($event->type());
        $builder = $this->db->table('usuarios u')
            ->select('DISTINCT u.id, u.empresa_id, u.email, u.email_notificaciones, u.telefono', false)
            ->join('usuario_roles ur', 'ur.usuario_id = u.id', 'inner')
            ->join('rol_permisos rp', 'rp.rol_id = ur.rol_id', 'inner')
            ->join('permisos p', 'p.id = rp.permiso_id', 'inner')
            ->where('u.empresa_id', $event->companyId())
            ->where('u.activo', 1)
            ->where('u.deleted_at', null)
            ->where('p.clave', $permission)
            ->where("EXISTS (SELECT 1 FROM usuario_roles ur_n INNER JOIN rol_permisos rp_n ON rp_n.rol_id = ur_n.rol_id INNER JOIN permisos p_n ON p_n.id = rp_n.permiso_id WHERE ur_n.usuario_id = u.id AND p_n.clave = 'notificaciones.ver')", null, false);

        if ($event->recipientUserIds() !== null) {
            $builder->whereIn('u.id', $event->recipientUserIds());
        }

        $recipients = [];
        foreach ($builder->get()->getResultArray() as $row) {
            $userId = (int) $row['id'];
            $allBranches = $this->db->table('usuario_roles ur')->join('roles r', 'r.id = ur.rol_id', 'inner')->where('ur.usuario_id', $userId)->where('r.nombre', 'Administrador')->countAllResults() > 0;
            $branchIds = array_map('intval', array_column($this->db->table('usuario_sucursales')->select('sucursal_id')->where('usuario_id', $userId)->get()->getResultArray(), 'sucursal_id'));
            if ($this->scope->allows($event->companyId(), $event->branchId(), (int) $row['empresa_id'], $allBranches, $branchIds)) {
                $rawNotificationEmail = $row['email_notificaciones'] ?? null;
                $resolved = $this->emails->resolveWithReason(
                    $rawNotificationEmail === null ? null : (string) $rawNotificationEmail,
                    (string) $row['email'],
                );
                if ($resolved['usedFallback'] && $rawNotificationEmail !== null && trim((string) $rawNotificationEmail) !== '') {
                    log_message('notice', 'Correo de resumen inválido para el usuario {user}; se usa el correo de ingreso.', [
                        'user' => $userId,
                    ]);
                }
                $recipients[] = new NotificationRecipient($userId, (int) $row['empresa_id'], $resolved['email'], $row['telefono'] === null ? null : (string) $row['telefono']);
            }
        }
        return $recipients;
    }

    private function permissionFor(string $type): string
    {
        return match (true) {
            str_starts_with($type, 'preventivo.') => 'planes.ver',
            str_starts_with($type, 'orden.') => 'ordenes.ver',
            str_starts_with($type, 'solicitud.') => 'solicitudes.revisar',
            str_starts_with($type, 'garantia.') => 'ordenes.ver',
            str_starts_with($type, 'empleado.vencimiento_') => 'empleados.ver',
            str_starts_with($type, 'equipo.vencimiento_') => 'equipos.ver',
            default => 'equipos.ver',
        };
    }
}
