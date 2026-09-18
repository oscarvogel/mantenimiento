<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications;

use App\Application\Notifications\Port\GlobalNotificationSettingsStore;
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
        private readonly GlobalNotificationSettingsStore $settings,
        private ?BaseConnection $db = null,
    ) {
        $this->db ??= Database::connect();
    }

    public function scheduleDriverForEvent(NotifiableEvent $event): void
    {
        if (! $this->gateway->available()) {
            return;
        }

        $company = $this->db->table('empresas')
            ->select('notificaciones_whatsapp_habilitadas, whatsapp_instance_id')
            ->where('id', $event->companyId())
            ->where('estado', 1)
            ->where('deleted_at', null)
            ->get()
            ->getRowArray();
        if ($company === null || (int) ($company['notificaciones_whatsapp_habilitadas'] ?? 0) !== 1) {
            return;
        }
        $instanceId = trim((string) ($company['whatsapp_instance_id'] ?? ''));
        if ($instanceId === '') {
            $globalSettings = $this->settings->get();
            $instanceId = trim((string) ($globalSettings['whatsapp_instance_id'] ?? 'default'));
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

        $realPhone = $this->gateway->normalizePhone((string) ($driver['telefono'] ?? ''));
        $employeeId = (int) $driver['empleado_id'];
        $key = $event->logicalKey() . ':chofer:' . $employeeId . ':whatsapp';
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        $settings = $this->settings->get();
        $pilotEnabled = (bool) ($settings['whatsapp_pilot_enabled'] ?? true);
        $pilotPhone = $this->gateway->normalizePhone((string) ($settings['whatsapp_pilot_phone'] ?? ''));
        $phone = $pilotEnabled ? $pilotPhone : $realPhone;
        $message = $this->message($event, $driver, $pilotEnabled, $realPhone !== null);

        if ($phone === null) {
            $this->db->table('notificacion_whatsapp_entregas')->ignore(true)->insert([
                'empresa_id' => $event->companyId(),
                'equipo_id' => $equipmentId,
                'empleado_id' => $employeeId,
                'tipo_evento' => $event->type(),
                'clave_entrega' => $key,
                'external_ref' => 'mantenimiento:' . $event->logicalKey() . ':chofer:' . $employeeId,
                'telefono' => null,
                'instance_id' => $instanceId,
                'mensaje' => $message,
                'estado' => 'OMITIDA',
                'ultimo_error' => $pilotEnabled
                    ? 'Modo piloto activo pero no hay un teléfono piloto válido configurado.'
                    : 'El chofer asignado no tiene un celular válido para WhatsApp.',
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
            'instance_id' => $instanceId,
            'mensaje' => $message,
            'estado' => 'PENDIENTE',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function due(int $limit): array
    {
        $rows = $this->db->table('notificacion_whatsapp_entregas')
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

        if ($rows === []) {
            return [];
        }

        $globalSettings = $this->settings->get();
        $globalInstanceId = trim((string) ($globalSettings['whatsapp_instance_id'] ?? 'default'));
        $companyIds = array_values(array_unique(array_map(static fn (array $row): int => (int) ($row['empresa_id'] ?? 0), $rows)));
        $instancesByCompany = [];

        if ($companyIds !== []) {
            foreach ($this->db->table('empresas')
                ->select('id, whatsapp_instance_id')
                ->whereIn('id', $companyIds)
                ->get()
                ->getResultArray() as $company) {
                $companyInstanceId = trim((string) ($company['whatsapp_instance_id'] ?? ''));
                $instancesByCompany[(int) $company['id']] = $companyInstanceId !== ''
                    ? $companyInstanceId
                    : $globalInstanceId;
            }
        }

        foreach ($rows as &$row) {
            $companyId = (int) ($row['empresa_id'] ?? 0);
            $row['instance_id'] = $instancesByCompany[$companyId] ?? $globalInstanceId;
        }
        unset($row);

        return $rows;
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
    private function message(NotifiableEvent $event, array $driver, bool $pilotEnabled, bool $realPhoneValid): string
    {
        $name = trim((string) ($driver['nombre'] ?? '') . ' ' . (string) ($driver['apellido'] ?? ''));
        $greeting = $name === '' ? 'Hola.' : 'Hola ' . $name . '.';
        $pilotHeader = $pilotEnabled
            ? "🧪 *PRUEBA CONTROLADA · NO ENVIADO AL DESTINATARIO REAL*\n"
                . "*Destinatario previsto:* " . ($name === '' ? 'Chofer asignado' : $name) . "\n"
                . "*Teléfono real:* " . ($realPhoneValid ? 'configurado' : 'no válido o no cargado') . "\n\n"
            : '';

        return $pilotHeader
            . "*Vogel Consultoría · Mantenimiento*\n\n"
            . $greeting . "\n\n"
            . "⚠️ *" . trim($event->title()) . "*\n"
            . rtrim(trim($event->summary()), ".") . ".\n\n"
            . "Por favor, revisá la situación del equipo y coordiná la regularización con el responsable.\n\n"
            . "_Aviso automático del Sistema de Mantenimiento._";
    }
}
