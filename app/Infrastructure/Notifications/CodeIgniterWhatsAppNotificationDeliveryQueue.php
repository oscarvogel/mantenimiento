<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications;

use App\Application\Notifications\Port\NotificationClock;
use App\Application\Notifications\Port\WhatsAppNotificationDeliveryQueue;
use App\Application\Notifications\Port\WhatsAppNotificationGateway;
use App\Domain\Notifications\NotifiableEvent;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

final class CodeIgniterWhatsAppNotificationDeliveryQueue implements WhatsAppNotificationDeliveryQueue
{
    public function __construct(
        private readonly NotificationClock $clock,
        private readonly WhatsAppNotificationGateway $gateway,
        private ?BaseConnection $db = null,
    ) {
        $this->db ??= Database::connect();
    }

    public function scheduleDriverForEvent(NotifiableEvent $event): void
    {
        if (! $this->gateway->available()) {
            return;
        }

        if (! in_array($event->type(), ['equipo.vencimiento_proximo', 'equipo.vencimiento_vencido'], true)
            || $event->entityType() !== 'equipo') {
            return;
        }

        $equipmentId = (int) $event->entityId();
        if ($equipmentId <= 0) {
            return;
        }

        $driver = $this->db->table('employee_equipment_assignments a')
            ->select('a.empleado_id, emp.nombre, emp.apellido, emp.telefono')
            ->join('empleados emp', 'emp.id = a.empleado_id AND emp.empresa_id = a.empresa_id', 'inner')
            ->where('a.empresa_id', $event->companyId())
            ->where('a.equipo_id', $equipmentId)
            ->where('a.rol', 'CHOFER')
            ->where('a.fecha_hasta', null)
            ->where('emp.activo', 1)
            ->where('emp.deleted_at', null)
            ->orderBy('a.id', 'DESC')
            ->get()
            ->getRowArray();

        if ($driver === null) {
            return;
        }

        $phone = $this->gateway->normalizePhone((string) ($driver['telefono'] ?? ''));
        $employeeId = (int) $driver['empleado_id'];
        $key = $event->logicalKey() . ':chofer:' . $employeeId . ':whatsapp';
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        if ($phone === null) {
            $this->db->table('notificacion_whatsapp_entregas')->ignore(true)->insert([
                'empresa_id' => $event->companyId(),
                'equipo_id' => $equipmentId,
                'empleado_id' => $employeeId,
                'tipo_evento' => $event->type(),
                'clave_entrega' => $key,
                'external_ref' => 'mantenimiento:' . $event->logicalKey() . ':chofer:' . $employeeId,
                'telefono' => null,
                'mensaje' => $this->message($event, $driver),
                'estado' => 'OMITIDA',
                'ultimo_error' => 'El chofer asignado no tiene un celular válido para WhatsApp.',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            return;
        }

        $this->db->table('notificacion_whatsapp_entregas')->ignore(true)->insert([
            'empresa_id' => $event->companyId(),
            'equipo_id' => $equipmentId,
            'empleado_id' => $employeeId,
            'tipo_evento' => $event->type(),
            'clave_entrega' => $key,
            'external_ref' => 'mantenimiento:' . $event->logicalKey() . ':chofer:' . $employeeId,
            'telefono' => $phone,
            'mensaje' => $this->message($event, $driver),
            'estado' => 'PENDIENTE',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function due(int $limit): array
    {
        return $this->db->table('notificacion_whatsapp_entregas')
            ->whereIn('estado', ['PENDIENTE', 'REINTENTO'])
            ->where('telefono IS NOT NULL', null, false)
            ->groupStart()
                ->where('proximo_intento', null)
                ->orWhere('proximo_intento <=', $this->clock->now()->format('Y-m-d H:i:s'))
            ->groupEnd()
            ->orderBy('id', 'ASC')
            ->limit(max(1, min(1000, $limit)))
            ->get()
            ->getResultArray();
    }

    public function accepted(int $deliveryId, string $messageId, string $status): void
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $this->db->table('notificacion_whatsapp_entregas')->where('id', $deliveryId)->update([
            'estado' => 'ACEPTADA',
            'gateway_message_id' => $messageId,
            'gateway_status' => $status,
            'enviada_en' => $now,
            'ultimo_error' => null,
            'updated_at' => $now,
        ]);
    }

    public function skipped(int $deliveryId, string $reason): void
    {
        $this->db->table('notificacion_whatsapp_entregas')->where('id', $deliveryId)->update([
            'estado' => 'OMITIDA',
            'ultimo_error' => mb_substr($reason, 0, 1000),
            'updated_at' => $this->clock->now()->format('Y-m-d H:i:s'),
        ]);
    }

    public function failed(int $deliveryId, string $error, bool $retryable): void
    {
        $row = $this->db->table('notificacion_whatsapp_entregas')
            ->select('intentos')
            ->where('id', $deliveryId)
            ->get()
            ->getRowArray();
        $attempts = ((int) ($row['intentos'] ?? 0)) + 1;
        $now = $this->clock->now();

        $this->db->table('notificacion_whatsapp_entregas')->where('id', $deliveryId)->update([
            'estado' => $retryable ? 'REINTENTO' : 'FALLIDA',
            'intentos' => $attempts,
            'proximo_intento' => $retryable
                ? $now->modify('+' . min(3600, 60 * (2 ** ($attempts - 1))) . ' seconds')->format('Y-m-d H:i:s')
                : null,
            'ultimo_error' => mb_substr($error, 0, 1000),
            'updated_at' => $now->format('Y-m-d H:i:s'),
        ]);
    }

    /** @param array<string,mixed> $driver */
    private function message(NotifiableEvent $event, array $driver): string
    {
        $name = trim((string) ($driver['nombre'] ?? '') . ' ' . (string) ($driver['apellido'] ?? ''));
        $prefix = $name === '' ? '' : 'Hola ' . $name . '. ';

        return $prefix . $event->title() . ".\n" . $event->summary()
            . ".\nAviso automático del Sistema de Mantenimiento.";
    }
}
