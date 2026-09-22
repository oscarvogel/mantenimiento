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
use App\Infrastructure\PublicEquipmentAccess\CodeIgniterPublicEquipmentTokenRepository;
use Throwable;

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
        // También para vencimientos, el piloto sólo redirige destinatarios reales válidos.
        // Si el chofer no tiene celular válido, queda omitido y se informa al administrador.
        $phone = $realPhone === null ? null : ($pilotEnabled ? $pilotPhone : $realPhone);
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

    public function scheduleWeeklyReadingReminders(bool $force = false, ?string $testKey = null, ?int $maxScheduled = null): int
    {
        if (! $this->gateway->available()) {
            return 0;
        }

        $settings = $this->settings->get();
        $pilotEnabled = (bool) ($settings['whatsapp_pilot_enabled'] ?? true);
        $pilotPhone = $this->gateway->normalizePhone((string) ($settings['whatsapp_pilot_phone'] ?? ''));
        $globalInstanceId = trim((string) ($settings['whatsapp_instance_id'] ?? 'default'));
        $now = $this->clock->now();

        if (! $force && ! $this->weeklyReadingReminderIsDue($now)) {
            return 0;
        }

        $weekKey = $now->format('o-\\WW');
        $timestamp = $now->format('Y-m-d H:i:s');
        $testSuffix = trim((string) $testKey) === ''
            ? ''
            : ':prueba:' . preg_replace('/[^A-Za-z0-9_.-]+/', '-', trim((string) $testKey));

        $rows = $this->db->table('employee_equipment_assignments a')
            ->select('a.id assignment_id, a.empresa_id, a.equipo_id, a.empleado_id')
            ->select('emp.nombre, emp.apellido, emp.telefono')
            ->select('e.codigo equipo_codigo, e.patente, e.estado equipo_estado')
            ->select('te.controla_km')
            ->select('co.notificaciones_whatsapp_habilitadas, co.whatsapp_instance_id, co.idioma_notificaciones')
            ->join('empleados emp', 'emp.id = a.empleado_id AND emp.empresa_id = a.empresa_id', 'inner')
            ->join('equipos e', 'e.id = a.equipo_id AND e.empresa_id = a.empresa_id', 'inner')
            ->join('tipos_equipo te', 'te.id = e.tipo_equipo_id', 'inner')
            ->join('empresas co', 'co.id = a.empresa_id', 'inner')
            ->where('a.rol', 'CHOFER')
            ->where('a.fecha_hasta', null)
            ->where('emp.activo', 1)
            ->where('emp.deleted_at', null)
            ->where('e.estado', 'ACTIVO')
            ->where('e.deleted_at', null)
            ->where('te.controla_km', 1)
            ->where('co.estado', 1)
            ->where('co.deleted_at', null)
            ->where('co.notificaciones_whatsapp_habilitadas', 1)
            ->orderBy('a.id', 'DESC')
            ->get()
            ->getResultArray();

        $seenEquipment = [];
        $scheduled = 0;
        foreach ($rows as $row) {
            $equipmentId = (int) $row['equipo_id'];
            if ($equipmentId <= 0 || isset($seenEquipment[$equipmentId])) {
                continue;
            }
            $seenEquipment[$equipmentId] = true;

            $employeeId = (int) $row['empleado_id'];
            $companyId = (int) $row['empresa_id'];
            $realPhone = $this->gateway->normalizePhone((string) ($row['telefono'] ?? ''));
            // El modo piloto redirige un destinatario REAL válido al teléfono piloto.
            // Un chofer sin celular válido nunca debe convertirse en destinatario enviable.
            $phone = $realPhone === null ? null : ($pilotEnabled ? $pilotPhone : $realPhone);
            $instanceId = trim((string) ($row['whatsapp_instance_id'] ?? ''));
            if ($instanceId === '') {
                $instanceId = $globalInstanceId;
            }

            $name = trim((string) ($row['nombre'] ?? '') . ' ' . (string) ($row['apellido'] ?? ''));
            $equipmentLabel = trim((string) ($row['equipo_codigo'] ?? ''));
            $plate = trim((string) ($row['patente'] ?? ''));
            if ($plate !== '' && mb_strtoupper($plate) !== mb_strtoupper($equipmentLabel)) {
                $equipmentLabel .= ($equipmentLabel === '' ? '' : ' · ') . $plate;
            }
            if ($equipmentLabel === '') {
                $equipmentLabel = 'Equipo #' . $equipmentId;
            }

            $token = null;
            try {
                $token = (new CodeIgniterPublicEquipmentTokenRepository($this->db))
                    ->ensureActivePlainTokenForEquipment($companyId, $equipmentId, $timestamp);
            } catch (Throwable $exception) {
                log_message('warning', 'No se pudo asegurar el acceso público para recordatorio semanal del equipo {equipment}: {message}', [
                    'equipment' => $equipmentId,
                    'message' => $exception->getMessage(),
                ]);
            }

            if (! is_string($token) || trim($token) === '') {
                continue;
            }

            $url = base_url('mantenimiento/publico/equipo/' . rawurlencode($token) . '/lectura');
            $deliveryKey = 'recordatorio_lectura_semanal:empresa:' . $companyId
                . ':equipo:' . $equipmentId
                . ':chofer:' . $employeeId
                . ':semana:' . $weekKey
                . $testSuffix;

            $locale = $this->normalizeLocale((string) ($row['idioma_notificaciones'] ?? 'ES'));
            $pilotHeader = $pilotEnabled
                ? ($locale === 'PT'
                    ? "🧪 *TESTE CONTROLADO · NÃO ENVIADO AO DESTINATÁRIO REAL*\n"
                        . "*Destinatário previsto:* " . ($name === '' ? 'Motorista atribuído' : $name) . "\n"
                        . "*Telefone real:* " . ($realPhone === null ? 'inválido ou não informado' : 'configurado') . "\n\n"
                    : "🧪 *PRUEBA CONTROLADA · NO ENVIADO AL DESTINATARIO REAL*\n"
                        . "*Destinatario previsto:* " . ($name === '' ? 'Chofer asignado' : $name) . "\n"
                        . "*Teléfono real:* " . ($realPhone === null ? 'no válido o no cargado' : 'configurado') . "\n\n")
                : '';

            $message = $locale === 'PT'
                ? $pilotHeader
                    . "*Vogel Consultoría · Manutenção*\n\n"
                    . ($name === '' ? 'Olá.' : 'Olá ' . $name . '.') . "\n\n"
                    . "🚛 *Lembrete semanal de quilometragem*\n"
                    . "Por favor, informe a quilometragem atual de *" . $equipmentLabel . "* para manter o acompanhamento de manutenção atualizado.\n\n"
                    . "👉 *Informar quilometragem:*\n" . $url . "\n\n"
                    . "Não é necessário fazer login: o link corresponde ao acesso público do equipamento.\n\n"
                    . "🌐 *Vogel Consultoría · Manutenção*\n"
                    . "https://vogelconsultoria.com.ar/mantenimiento\n\n"
                    . "_Aviso automático do Sistema de Manutenção._"
                : $pilotHeader
                    . "*Vogel Consultoría · Mantenimiento*\n\n"
                    . ($name === '' ? 'Hola.' : 'Hola ' . $name . '.') . "\n\n"
                    . "🚛 *Recordatorio semanal de kilometraje*\n"
                    . "Por favor, cargá el kilometraje actual de *" . $equipmentLabel . "* para mantener actualizado el seguimiento de mantenimiento.\n\n"
                    . "👉 *Cargar kilometraje:*\n" . $url . "\n\n"
                    . "No necesitás iniciar sesión: el enlace corresponde al acceso público del equipo.\n\n"
                    . "🌐 *Vogel Consultoría · Mantenimiento*\n"
                    . "https://vogelconsultoria.com.ar/mantenimiento\n\n"
                    . "_Aviso automático del Sistema de Mantenimiento._";

            $this->db->table('notificacion_whatsapp_entregas')->ignore(true)->insert([
                'empresa_id' => $companyId,
                'equipo_id' => $equipmentId,
                'empleado_id' => $employeeId,
                'tipo_evento' => 'equipo.recordatorio_lectura_semanal',
                'clave_entrega' => $deliveryKey,
                'external_ref' => 'mantenimiento:' . $deliveryKey,
                'telefono' => $phone,
                'instance_id' => $instanceId,
                'mensaje' => $message,
                'estado' => $phone === null ? 'OMITIDA' : 'PENDIENTE',
                'ultimo_error' => $phone === null
                    ? ($pilotEnabled
                        ? 'Modo piloto activo pero no hay un teléfono piloto válido configurado.'
                        : 'El chofer asignado no tiene un celular válido para WhatsApp.')
                    : null,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);

            if ($phone !== null && $this->db->affectedRows() > 0) {
                $scheduled++;
                if ($maxScheduled !== null && $scheduled >= max(1, $maxScheduled)) {
                    break;
                }
            }
        }

        return $scheduled;
    }

    private function weeklyReadingReminderIsDue(\DateTimeInterface $now): bool
    {
        $day = max(1, min(7, (int) env('alerts.weeklyReadingReminderDay', 1)));
        $time = trim((string) env('alerts.weeklyReadingReminderTime', '08:00'));
        if (preg_match('/^(\d{1,2}):(\d{2})$/', $time, $matches) !== 1) {
            $time = '08:00';
            preg_match('/^(\d{1,2}):(\d{2})$/', $time, $matches);
        }

        $hour = max(0, min(23, (int) ($matches[1] ?? 8)));
        $minute = max(0, min(59, (int) ($matches[2] ?? 0)));
        $weekStart = \DateTimeImmutable::createFromInterface($now)
            ->modify('monday this week')
            ->setTime(0, 0);
        $dueAt = $weekStart
            ->modify('+' . ($day - 1) . ' days')
            ->setTime($hour, $minute);

        return $now >= $dueAt;
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
            // Primero salen vencimientos/alertas; el recordatorio semanal no debe
            // demorar una alerta más urgente cuando la cola supera el batch del cron.
            ->orderBy("tipo_evento = 'equipo.recordatorio_lectura_semanal'", 'ASC', false)
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
            . "🌐 *Vogel Consultoría · Mantenimiento*\n"
            . "https://vogelconsultoria.com.ar/mantenimiento\n\n"
            . "_Aviso automático del Sistema de Mantenimiento._";
    }

    private function normalizeLocale(string $locale): string
    {
        return strtoupper(trim($locale)) === 'PT' ? 'PT' : 'ES';
    }
}
