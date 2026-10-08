<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications;

use App\Application\Notifications\Port\GlobalNotificationSettingsStore;
use App\Application\Notifications\Port\NotificationClock;
use App\Application\Notifications\Port\WhatsAppNotificationDeliveryQueue;
use App\Application\Notifications\Port\WhatsAppNotificationGateway;
use App\Application\Notifications\ReadingReminderMessageBuilder;
use App\Application\Notifications\UserWhatsAppDigestSchedule;
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

    public function scheduleUserDailyDigests(): int
    {
        if (! $this->gateway->available()) {
            return 0;
        }

        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        $slot = (new UserWhatsAppDigestSchedule())->slot(
            $now,
            (string) env('alerts.userWhatsAppDigestTime', '08:00'),
        );
        if ($slot === null) {
            return 0;
        }

        $settings = $this->settings->get();
        $pilotEnabled = (bool) ($settings['whatsapp_pilot_enabled'] ?? true);
        $pilotPhone = $this->gateway->normalizePhone((string) ($settings['whatsapp_pilot_phone'] ?? ''));
        $globalInstanceId = trim((string) ($settings['whatsapp_instance_id'] ?? 'default'));
        $dateKey = $slot->format('Ymd');
        $scheduled = 0;

        $users = $this->db->table('usuarios u')
            ->select('u.id, u.empresa_id, u.nombre, u.telefono')
            ->select('co.whatsapp_instance_id')
            ->join('empresas co', 'co.id = u.empresa_id', 'inner')
            ->where('u.activo', 1)
            ->where('u.deleted_at', null)
            ->where('co.estado', 1)
            ->where('co.deleted_at', null)
            ->where('co.notificaciones_whatsapp_habilitadas', 1)
            ->where("EXISTS (SELECT 1 FROM usuario_roles ur INNER JOIN rol_permisos rp ON rp.rol_id = ur.rol_id INNER JOIN permisos p ON p.id = rp.permiso_id WHERE ur.usuario_id = u.id AND p.clave = 'notificaciones.ver')", null, false)
            ->get()
            ->getResultArray();

        foreach ($users as $user) {
            $userId = (int) ($user['id'] ?? 0);
            $companyId = (int) ($user['empresa_id'] ?? 0);
            if ($userId <= 0 || $companyId <= 0) {
                continue;
            }

            $realPhone = $this->gateway->normalizePhone((string) ($user['telefono'] ?? ''));
            $phone = $realPhone === null ? null : ($pilotEnabled ? $pilotPhone : $realPhone);
            if ($phone === null) {
                continue;
            }

            $key = 'resumen_usuario_diario:empresa:' . $companyId
                . ':usuario:' . $userId
                . ':fecha:' . $dateKey;
            if ($this->db->table('notificacion_whatsapp_entregas')
                ->where('clave_entrega', $key)
                ->countAllResults() > 0) {
                continue;
            }

            $instanceId = trim((string) ($user['whatsapp_instance_id'] ?? ''));
            if ($instanceId === '') {
                $instanceId = $globalInstanceId;
            }

            $timestamp = $now->format('Y-m-d H:i:s');
            $this->db->table('notificacion_whatsapp_entregas')->ignore(true)->insert([
                'empresa_id' => $companyId,
                'equipo_id' => null,
                'empleado_id' => null,
                'usuario_id' => $userId,
                'tipo_evento' => 'usuario.resumen_diario',
                'clave_entrega' => $key,
                'external_ref' => 'mantenimiento:' . $key,
                'telefono' => $phone,
                'instance_id' => $instanceId,
                'mensaje' => '',
                'estado' => 'PENDIENTE',
                'proximo_intento' => $slot->format('Y-m-d H:i:s'),
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
            $scheduled++;
        }

        return $scheduled;
    }

    public function scheduleUserDailyDigestTest(int $userId, int $equipmentId, string $testKey): int
    {
        if (! $this->gateway->available()) {
            return 0;
        }

        $user = $this->db->table('usuarios u')
            ->select('u.id, u.empresa_id, u.telefono, co.whatsapp_instance_id')
            ->join('empresas co', 'co.id = u.empresa_id', 'inner')
            ->where('u.id', $userId)
            ->where('u.activo', 1)
            ->where('u.deleted_at', null)
            ->where('co.estado', 1)
            ->where('co.deleted_at', null)
            ->where('co.notificaciones_whatsapp_habilitadas', 1)
            ->get()
            ->getRowArray();
        if ($user === null) {
            return 0;
        }

        $equipment = $this->db->table('equipos')
            ->select('id')
            ->where('id', $equipmentId)
            ->where('empresa_id', (int) $user['empresa_id'])
            ->where('estado', 'ACTIVO')
            ->where('deleted_at', null)
            ->get()
            ->getRowArray();
        if ($equipment === null) {
            return 0;
        }

        $settings = $this->settings->get();
        $pilotEnabled = (bool) ($settings['whatsapp_pilot_enabled'] ?? true);
        $pilotPhone = $this->gateway->normalizePhone((string) ($settings['whatsapp_pilot_phone'] ?? ''));
        $realPhone = $this->gateway->normalizePhone((string) ($user['telefono'] ?? ''));
        $phone = $pilotEnabled ? $pilotPhone : $realPhone;
        if ($phone === null) {
            return 0;
        }

        $instanceId = trim((string) ($user['whatsapp_instance_id'] ?? ''));
        if ($instanceId === '') {
            $instanceId = trim((string) ($settings['whatsapp_instance_id'] ?? 'default'));
        }

        $safeTestKey = preg_replace('/[^A-Za-z0-9_.-]+/', '-', trim($testKey));
        if ($safeTestKey === '') {
            return 0;
        }

        $companyId = (int) $user['empresa_id'];
        $key = 'resumen_usuario_diario:empresa:' . $companyId
            . ':usuario:' . $userId
            . ':prueba:' . $safeTestKey;
        if ($this->db->table('notificacion_whatsapp_entregas')->where('clave_entrega', $key)->countAllResults() > 0) {
            return 0;
        }

        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $this->db->table('notificacion_whatsapp_entregas')->insert([
            'empresa_id' => $companyId,
            'equipo_id' => $equipmentId,
            'empleado_id' => null,
            'usuario_id' => $userId,
            'tipo_evento' => 'usuario.resumen_diario',
            'clave_entrega' => $key,
            'external_ref' => 'mantenimiento:' . $key,
            'telefono' => $phone,
            'instance_id' => $instanceId,
            'mensaje' => '',
            'estado' => 'PENDIENTE',
            'proximo_intento' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return 1;
    }

    public function scheduleDriverForEvent(NotifiableEvent $event): void
    {
        if (! $this->gateway->available()) {
            return;
        }

        if (in_array($event->type(), ['preventivo.proximo', 'preventivo.vencido'], true)
            && $event->entityType() === 'plan_mantenimiento') {
            $this->schedulePreventiveDriverEvent($event);
            return;
        }

        if (! in_array($event->type(), ['equipo.vencimiento_proximo', 'equipo.vencimiento_vencido'], true)
            || $event->entityType() !== 'equipo') {
            return;
        }

        $companyId = $event->companyId();
        $equipmentId = (int) $event->entityId();
        if ($equipmentId <= 0) {
            return;
        }

        $company = $this->db->table('empresas')
            ->select('razon_social, nombre_fantasia, notificaciones_whatsapp_habilitadas, whatsapp_instance_id')
            ->where('id', $companyId)
            ->where('estado', 1)
            ->where('deleted_at', null)
            ->get()->getRowArray();
        if ($company === null || (int) ($company['notificaciones_whatsapp_habilitadas'] ?? 0) !== 1) {
            return;
        }

        $driver = $this->db->table('employee_equipment_assignments a')
            ->select('a.empleado_id, emp.nombre, emp.apellido, emp.telefono')
            ->join('empleados emp', 'emp.id = a.empleado_id AND emp.empresa_id = a.empresa_id', 'inner')
            ->where('a.empresa_id', $companyId)
            ->where('a.equipo_id', $equipmentId)
            ->where('a.rol', 'CHOFER')
            ->where('a.fecha_hasta', null)
            ->where('emp.activo', 1)
            ->where('emp.deleted_at', null)
            ->orderBy('a.id', 'DESC')
            ->get()->getRowArray();
        if ($driver === null) {
            return;
        }

        $employeeId = (int) $driver['empleado_id'];
        $today = new \DateTimeImmutable($this->clock->now()->format('Y-m-d'));
        $todayKey = $today->format('Ymd');
        $key = 'resumen_vencimientos:empresa:' . $companyId . ':chofer:' . $employeeId . ':fecha:' . $todayKey;
        if ($this->db->table('notificacion_whatsapp_entregas')->where('clave_entrega', $key)->countAllResults() > 0) {
            return;
        }

        $assignedEquipment = $this->db->table('employee_equipment_assignments')
            ->select('equipo_id')
            ->where('empresa_id', $companyId)
            ->where('empleado_id', $employeeId)
            ->where('rol', 'CHOFER')
            ->where('fecha_hasta', null)
            ->get()->getResultArray();
        $equipmentIds = array_values(array_unique(array_filter(array_map(
            static fn (array $row): int => (int) ($row['equipo_id'] ?? 0),
            $assignedEquipment,
        ))));
        if ($equipmentIds === []) {
            return;
        }

        $builder = $this->db->table('vencimientos v')
            ->select('v.id, v.equipo_id, v.fecha_vencimiento, t.nombre tipo_nombre, t.dias_aviso_previo, e.codigo equipo_codigo, e.patente')
            ->join('tipos_vencimiento t', 't.id = v.tipo_vencimiento_id AND t.empresa_id = v.empresa_id', 'inner')
            ->join('equipos e', 'e.id = v.equipo_id AND e.empresa_id = v.empresa_id', 'inner')
            ->where('v.empresa_id', $companyId)
            ->whereIn('v.equipo_id', $equipmentIds)
            ->where('v.sujeto_tipo', 'EQUIPO')
            ->where('v.activo', 1)
            ->where('v.deleted_at', null)
            ->where('t.deleted_at', null);

        if ($this->db->tableExists('vencimiento_regularizaciones')) {
            $builder->where(
                'NOT EXISTS (SELECT 1 FROM vencimiento_regularizaciones vr'
                . ' WHERE vr.empresa_id = v.empresa_id AND vr.vencimiento_id = v.id'
                . " AND vr.estado = 'PENDIENTE')",
                null,
                false,
            );
        }

        $items = [];
        foreach ($builder->get()->getResultArray() as $row) {
            try {
                $expires = new \DateTimeImmutable((string) $row['fecha_vencimiento']);
            } catch (\Throwable) {
                continue;
            }
            $expires = new \DateTimeImmutable($expires->format('Y-m-d'));
            $days = (int) $today->diff($expires)->format('%r%a');
            $warningDays = max(0, (int) ($row['dias_aviso_previo'] ?? 30));
            $milestones = array_values(array_unique([$warningDays, 15, 7, 0]));
            if ($days >= 0 && ! in_array($days, $milestones, true)) {
                continue;
            }

            $equipmentLabel = trim((string) ($row['equipo_codigo'] ?? ''));
            $plate = trim((string) ($row['patente'] ?? ''));
            if ($plate !== '' && mb_strtoupper($plate) !== mb_strtoupper($equipmentLabel)) {
                $equipmentLabel .= ($equipmentLabel === '' ? '' : ' · ') . $plate;
            }
            if ($equipmentLabel === '') {
                $equipmentLabel = 'Equipo #' . (int) $row['equipo_id'];
            }

            $items[] = [
                'equipment_id' => (int) $row['equipo_id'],
                'equipment' => $equipmentLabel,
                'type' => trim((string) $row['tipo_nombre']),
                'expires' => $expires,
                'days' => $days,
            ];
        }
        if ($items === []) {
            return;
        }

        usort($items, static function (array $a, array $b): int {
            $aOverdue = $a['days'] < 0;
            $bOverdue = $b['days'] < 0;
            if ($aOverdue !== $bOverdue) {
                return $aOverdue ? -1 : 1;
            }
            return $aOverdue
                ? $a['days'] <=> $b['days']
                : $a['days'] <=> $b['days'];
        });

        $settings = $this->settings->get();
        $pilotEnabled = (bool) ($settings['whatsapp_pilot_enabled'] ?? true);
        $pilotPhone = $this->gateway->normalizePhone((string) ($settings['whatsapp_pilot_phone'] ?? ''));
        $realPhone = $this->gateway->normalizePhone((string) ($driver['telefono'] ?? ''));
        $phone = $realPhone === null ? null : ($pilotEnabled ? $pilotPhone : $realPhone);

        $instanceId = trim((string) ($company['whatsapp_instance_id'] ?? ''));
        if ($instanceId === '') {
            $instanceId = trim((string) ($settings['whatsapp_instance_id'] ?? 'default'));
        }
        $companyName = trim((string) ($company['nombre_fantasia'] ?? ''));
        if ($companyName === '') {
            $companyName = trim((string) ($company['razon_social'] ?? ''));
        }
        $message = $this->expirationDigestMessage(
            $items,
            $driver,
            $pilotEnabled,
            $realPhone !== null,
            $companyName,
        );
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        $this->db->table('notificacion_whatsapp_entregas')->ignore(true)->insert([
            'empresa_id' => $companyId,
            'equipo_id' => (int) $items[0]['equipment_id'],
            'empleado_id' => $employeeId,
            'tipo_evento' => 'equipo.resumen_vencimientos',
            'clave_entrega' => $key,
            'external_ref' => 'mantenimiento:' . $key,
            'telefono' => $phone,
            'instance_id' => $instanceId,
            'mensaje' => $message,
            'estado' => $phone === null ? 'OMITIDA' : 'PENDIENTE',
            'ultimo_error' => $phone === null
                ? ($pilotEnabled
                    ? 'Modo piloto activo pero no hay un teléfono piloto válido configurado.'
                    : 'El chofer asignado no tiene un celular válido para WhatsApp.')
                : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function schedulePreventiveDriverEvent(NotifiableEvent $event, ?string $testKey = null, bool $forcePilot = false): int
    {
        $companyId = $event->companyId();
        $planId = (int) $event->entityId();
        if ($planId <= 0) {
            return 0;
        }

        $plan = $this->db->table('planes_mantenimiento p')
            ->select('p.id, p.equipo_id, e.codigo equipo_codigo, e.patente, ts.nombre servicio_nombre')
            ->join('equipos e', 'e.id = p.equipo_id AND e.empresa_id = p.empresa_id', 'inner')
            ->join('tipos_servicio ts', 'ts.id = p.tipo_servicio_id', 'inner')
            ->where('p.id', $planId)->where('p.empresa_id', $companyId)
            ->where('p.activo', 1)->where('p.deleted_at', null)->where('e.deleted_at', null)
            ->get()->getRowArray();
        if ($plan === null) {
            return 0;
        }

        $equipmentId = (int) $plan['equipo_id'];
        $driver = $this->db->table('employee_equipment_assignments a')
            ->select('a.empleado_id, emp.nombre, emp.apellido, emp.telefono')
            ->join('empleados emp', 'emp.id = a.empleado_id AND emp.empresa_id = a.empresa_id', 'inner')
            ->where('a.empresa_id', $companyId)->where('a.equipo_id', $equipmentId)
            ->where('a.rol', 'CHOFER')->where('a.fecha_hasta', null)
            ->where('emp.activo', 1)->where('emp.deleted_at', null)
            ->orderBy('a.id', 'DESC')->get()->getRowArray();
        if ($driver === null) {
            return 0;
        }

        $company = $this->db->table('empresas')
            ->select('razon_social, nombre_fantasia, notificaciones_whatsapp_habilitadas, whatsapp_instance_id')
            ->where('id', $companyId)->where('estado', 1)->where('deleted_at', null)->get()->getRowArray();
        if ($company === null || (int) ($company['notificaciones_whatsapp_habilitadas'] ?? 0) !== 1) {
            return 0;
        }

        $settings = $this->settings->get();
        $pilotEnabled = (bool) ($settings['whatsapp_pilot_enabled'] ?? true);
        $effectivePilot = $pilotEnabled || $forcePilot;
        $pilotPhone = $this->gateway->normalizePhone((string) ($settings['whatsapp_pilot_phone'] ?? ''));
        $realPhone = $this->gateway->normalizePhone((string) ($driver['telefono'] ?? ''));
        $phone = $effectivePilot ? $pilotPhone : $realPhone;
        if ($phone === null) {
            return 0;
        }

        $employeeId = (int) $driver['empleado_id'];
        $cycle = preg_replace('/[^A-Za-z0-9_.:-]+/', '-', $event->logicalKey());
        $key = 'preventivo_chofer:empresa:' . $companyId . ':plan:' . $planId . ':chofer:' . $employeeId . ':ciclo:' . $cycle;
        if ($testKey !== null && trim($testKey) !== '') {
            $key .= ':prueba:' . preg_replace('/[^A-Za-z0-9_.-]+/', '-', trim($testKey));
        }
        if ($this->db->table('notificacion_whatsapp_entregas')->where('clave_entrega', $key)->countAllResults() > 0) {
            return 0;
        }

        $equipmentLabel = trim((string) ($plan['equipo_codigo'] ?? ''));
        $plate = trim((string) ($plan['patente'] ?? ''));
        if ($plate !== '' && mb_strtoupper($plate) !== mb_strtoupper($equipmentLabel)) {
            $equipmentLabel .= ($equipmentLabel === '' ? '' : ' · ') . $plate;
        }
        if ($equipmentLabel === '') {
            $equipmentLabel = 'Equipo #' . $equipmentId;
        }
        $companyName = trim((string) ($company['nombre_fantasia'] ?? '')) ?: trim((string) ($company['razon_social'] ?? 'Empresa'));
        $driverName = trim((string) ($driver['nombre'] ?? '') . ' ' . (string) ($driver['apellido'] ?? ''));
        $firstName = trim((string) ($driver['nombre'] ?? ''));
        $status = $event->type() === 'preventivo.vencido' ? 'MANTENIMIENTO VENCIDO' : 'MANTENIMIENTO PRÓXIMO';
        $pilotHeader = $effectivePilot
            ? "🧪 *PRUEBA CONTROLADA · NO ENVIADO AL DESTINATARIO REAL*\n"
                . "*Destinatario previsto:* " . ($driverName === '' ? 'Chofer asignado' : $driverName) . "\n"
                . "*Teléfono real:* " . ($realPhone === null ? 'no válido o no cargado' : 'configurado') . "\n\n"
            : '';
        $serviceName = trim((string) ($plan['servicio_nombre'] ?? 'Servicio preventivo'));
        $detail = trim($event->summary());
        if ($serviceName !== '' && str_starts_with(mb_strtolower($detail), mb_strtolower($serviceName))) {
            $detail = trim((string) preg_replace('/^[^·]+·?\\s*/u', '', $detail, 1));
        }
        $detail = preg_replace_callback('/(?<![\\d.,])(\\d{4,})(?![\\d.,])/u', static function (array $match): string {
            return number_format((int) $match[1], 0, ',', '.');
        }, $detail) ?? $detail;

        $message = $pilotHeader
            . "*" . $companyName . " · Mantenimiento*\n\n"
            . ($firstName === '' ? 'Hola 👋' : 'Hola ' . $firstName . ' 👋') . "\n\n"
            . "🔧 *" . $status . "*\n"
            . "🚛 *" . $equipmentLabel . "*\n"
            . "*" . ($serviceName === '' ? 'Servicio preventivo' : $serviceName) . "*\n"
            . ($detail === '' ? '' : ucfirst($detail) . ".\n")
            . "\nPor favor, coordiná este mantenimiento con el responsable de mantenimiento.\n\n"
            . "_Sistema de mantenimiento desarrollado por Vogel Consultoría._";

        $instanceId = trim((string) ($company['whatsapp_instance_id'] ?? ''));
        if ($instanceId === '') {
            $instanceId = trim((string) ($settings['whatsapp_instance_id'] ?? 'default'));
        }
        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $this->db->table('notificacion_whatsapp_entregas')->ignore(true)->insert([
            'empresa_id' => $companyId, 'equipo_id' => $equipmentId, 'empleado_id' => $employeeId,
            'tipo_evento' => $event->type(), 'clave_entrega' => $key, 'external_ref' => 'mantenimiento:' . $key,
            'telefono' => $phone, 'instance_id' => $instanceId, 'mensaje' => $message, 'estado' => 'PENDIENTE',
            'ultimo_error' => null, 'created_at' => $now, 'updated_at' => $now,
        ]);
        return 1;
    }

    public function schedulePreventivePilotTest(int $planId, string $testKey): int
    {
        $row = $this->db->table('planes_mantenimiento p')
            ->select('p.id, p.empresa_id, p.equipo_id, p.proximo_km, p.proximas_horas, p.proxima_fecha, e.codigo equipo_codigo, e.km_actual, e.horas_actuales, ts.nombre servicio_nombre')
            ->join('equipos e', 'e.id = p.equipo_id AND e.empresa_id = p.empresa_id', 'inner')
            ->join('tipos_servicio ts', 'ts.id = p.tipo_servicio_id', 'inner')
            ->where('p.id', $planId)->where('p.activo', 1)->where('p.deleted_at', null)->where('e.deleted_at', null)
            ->get()->getRowArray();
        if ($row === null) {
            return 0;
        }

        $parts = [];
        if ($row['proximo_km'] !== null && $row['km_actual'] !== null) {
            $remaining = (int) $row['proximo_km'] - (int) $row['km_actual'];
            $parts[] = $remaining < 0 ? 'excedido por ' . number_format(abs($remaining), 0, ',', '.') . ' km' : 'faltan ' . number_format($remaining, 0, ',', '.') . ' km';
        }
        if ($row['proximas_horas'] !== null && $row['horas_actuales'] !== null) {
            $remaining = (float) $row['proximas_horas'] - (float) $row['horas_actuales'];
            $parts[] = $remaining < 0 ? 'excedido por ' . abs($remaining) . ' h' : 'faltan ' . $remaining . ' h';
        }
        if ($row['proxima_fecha'] !== null) {
            $parts[] = 'fecha objetivo ' . (new \DateTimeImmutable((string) $row['proxima_fecha']))->format('d/m/Y');
        }
        $summary = $parts === [] ? 'Mantenimiento preventivo próximo' : implode(', ', $parts);
        $event = new NotifiableEvent(
            (int) $row['empresa_id'], null, 'preventivo.proximo',
            \App\Domain\Notifications\NotificationSeverity::WARNING,
            'Mantenimiento próximo: ' . (string) $row['equipo_codigo'], $summary,
            'plan_mantenimiento', (string) $planId, 'prueba_preventivo:plan:' . $planId,
            '/mantenimiento/planes?equipo_id=' . (int) $row['equipo_id'], $this->clock->now(),
        );
        return $this->schedulePreventiveDriverEvent($event, $testKey, true);
    }

    /** @param list<array{equipment_id:int,equipment:string,type:string,expires:\DateTimeImmutable,days:int}> $items */
    private function expirationDigestMessage(array $items, array $driver, bool $pilotEnabled, bool $realPhoneValid, string $companyName): string
    {
        $name = trim((string) ($driver['nombre'] ?? '') . ' ' . (string) ($driver['apellido'] ?? ''));
        $firstName = trim((string) ($driver['nombre'] ?? ''));
        $greeting = $firstName === '' ? 'Hola 👋' : 'Hola ' . $firstName . ' 👋';
        $pilotHeader = $pilotEnabled
            ? "🧪 *PRUEBA CONTROLADA · NO ENVIADO AL DESTINATARIO REAL*\n"
                . "*Destinatario previsto:* " . ($name === '' ? 'Chofer asignado' : $name) . "\n"
                . "*Teléfono real:* " . ($realPhoneValid ? 'configurado' : 'no válido o no cargado') . "\n\n"
            : '';

        $groups = [];
        foreach ($items as $item) {
            $groups[$item['equipment']][] = $item;
        }
        $body = '';
        foreach ($groups as $equipment => $group) {
            $body .= "🚛 *" . $equipment . "*\n";
            foreach ($group as $item) {
                $days = (int) $item['days'];
                $status = $days < 0
                    ? 'venció hace ' . abs($days) . ' día' . (abs($days) === 1 ? '' : 's')
                    : ($days === 0 ? 'vence hoy' : 'faltan ' . $days . ' día' . ($days === 1 ? '' : 's'));
                $body .= '• ' . $item['type'] . ' — vence ' . $item['expires']->format('d/m/Y') . ' (' . $status . ")\n";
            }
            $body .= "\n";
        }

        return $pilotHeader
            . "*" . ($companyName !== '' ? $companyName : 'Empresa') . " · Mantenimiento*\n\n"
            . $greeting . "\n"
            . "Tenés vencimientos para revisar:\n\n"
            . $body
            . "Por favor, coordiná la regularización con el responsable de mantenimiento.\n\n"
            . "_Sistema de mantenimiento desarrollado por Vogel Consultoría._";
    }

    public function scheduleWeeklyReadingReminders(bool $force = false, ?string $testKey = null, ?int $maxScheduled = null, ?string $forcedStage = null, bool $simulateMissingReading = false, ?int $onlyEquipmentId = null, bool $forcePilotDestination = false): int
    {
        if (! $this->gateway->available()) {
            return 0;
        }

        $settings = $this->settings->get();
        $pilotEnabled = (bool) ($settings['whatsapp_pilot_enabled'] ?? true);
        $pilotPhone = $this->gateway->normalizePhone((string) ($settings['whatsapp_pilot_phone'] ?? ''));
        $effectivePilot = $pilotEnabled || $forcePilotDestination;
        $globalInstanceId = trim((string) ($settings['whatsapp_instance_id'] ?? 'default'));
        $now = $this->clock->now();
        $forcedStage = strtolower(trim((string) $forcedStage));
        if ($forcedStage !== '' && ! in_array($forcedStage, ['initial', 'wednesday', 'friday'], true)) {
            throw new \InvalidArgumentException('La etapa semanal forzada no es válida.');
        }
        $stage = $forcedStage !== '' ? $forcedStage : ($force ? 'initial' : $this->weeklyReadingStage($now));
        if ($stage === null) {
            return 0;
        }

        $weekKey = $now->format('o-\\WW');
        $weekStart = \DateTimeImmutable::createFromInterface($now)->modify('monday this week')->setTime(0, 0);
        $timestamp = $now->format('Y-m-d H:i:s');
        $testSuffix = trim((string) $testKey) === ''
            ? ''
            : ':prueba:' . preg_replace('/[^A-Za-z0-9_.-]+/', '-', trim((string) $testKey));

        $rows = $this->db->table('employee_equipment_assignments a')
            ->select('a.id assignment_id, a.empresa_id, a.equipo_id, a.empleado_id')
            ->select('emp.nombre, emp.apellido, emp.telefono')
            ->select('e.codigo equipo_codigo, e.patente, e.estado equipo_estado, e.sucursal_id')
            ->select('te.controla_km')
            ->select('co.razon_social, co.nombre_fantasia, co.notificaciones_whatsapp_habilitadas, co.whatsapp_instance_id, co.idioma_notificaciones')
            ->select('s.idioma_notificaciones sucursal_idioma_notificaciones')
            ->join('empleados emp', 'emp.id = a.empleado_id AND emp.empresa_id = a.empresa_id', 'inner')
            ->join('equipos e', 'e.id = a.equipo_id AND e.empresa_id = a.empresa_id', 'inner')
            ->join('tipos_equipo te', 'te.id = e.tipo_equipo_id', 'inner')
            ->join('sucursales s', 's.id = e.sucursal_id AND s.empresa_id = e.empresa_id', 'inner')
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
        $limitedCandidates = 0;
        foreach ($rows as $row) {
            $equipmentId = (int) $row['equipo_id'];
            $companyId = (int) $row['empresa_id'];
            if ($onlyEquipmentId !== null && $equipmentId !== $onlyEquipmentId) {
                continue;
            }
            $equipmentKey = $companyId . ':' . $equipmentId;
            if ($companyId <= 0 || $equipmentId <= 0 || isset($seenEquipment[$equipmentKey])) {
                continue;
            }
            $seenEquipment[$equipmentKey] = true;

            $employeeId = (int) $row['empleado_id'];
            $branchId = (int) ($row['sucursal_id'] ?? 0);

            if ($stage !== 'initial' && ! $simulateMissingReading
                && $this->hasKilometerReadingSince($companyId, $equipmentId, $weekStart)) {
                continue;
            }

            $realPhone = $this->gateway->normalizePhone((string) ($row['telefono'] ?? ''));
            $phone = $realPhone === null ? null : ($effectivePilot ? $pilotPhone : $realPhone);
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
                $tokenRepository = new CodeIgniterPublicEquipmentTokenRepository($this->db);
                $token = $tokenRepository->ensureActivePlainTokenForEquipment($companyId, $equipmentId, $timestamp);
                if (is_string($token) && trim($token) !== '') {
                    $resolved = $tokenRepository->resolveActiveToken(hash('sha256', $token));
                    if ($resolved === null
                        || (int) ($resolved['empresa_id'] ?? 0) !== $companyId
                        || (int) ($resolved['equipo_id'] ?? 0) !== $equipmentId) {
                        log_message('error', 'Token público inconsistente para equipo {equipment}; se omite el WhatsApp para evitar enlace cruzado.', [
                            'equipment' => $equipmentId,
                        ]);
                        $token = null;
                    }
                }
            } catch (Throwable $exception) {
                log_message('warning', 'No se pudo asegurar el acceso público para seguimiento semanal del equipo {equipment}: {message}', [
                    'equipment' => $equipmentId,
                    'message' => $exception->getMessage(),
                ]);
            }
            if (! is_string($token) || trim($token) === '') {
                continue;
            }

            $url = base_url('mantenimiento/publico/equipo/' . rawurlencode($token) . '/lectura');
            $keyPrefix = match ($stage) {
                'wednesday' => 'seguimiento_lectura_miercoles',
                'friday' => 'seguimiento_lectura_viernes',
                default => 'recordatorio_lectura_semanal',
            };
            // El simulador piloto debe poder repetirse sin debilitar la idempotencia
            // productiva. Cada ejecución forzada usa una base aislada por testKey.
            if ($forcedStage !== '' && $testSuffix !== '') {
                $keyPrefix = 'simulacion_' . $stage . ':' . substr(hash('sha256', (string) $testKey), 0, 12);
            }
            $baseDeliveryKey = $keyPrefix . ':empresa:' . $companyId
                . ':equipo:' . $equipmentId
                . ':chofer:' . $employeeId
                . ':semana:' . $weekKey;
            $deliveryKey = $baseDeliveryKey . $testSuffix;

            if ($maxScheduled !== null) {
                $limitedCandidates++;
            }

            $existingDelivery = $this->db->table('notificacion_whatsapp_entregas');
            if ($testSuffix !== '') {
                $existingDelivery->like('clave_entrega', $baseDeliveryKey . ':prueba:', 'after');
            } else {
                $existingDelivery->where('clave_entrega', $baseDeliveryKey);
            }
            if ($existingDelivery->countAllResults() > 0) {
                if ($maxScheduled !== null && $limitedCandidates >= max(1, $maxScheduled)) {
                    break;
                }
                continue;
            }

            $branchLocale = trim((string) ($row['sucursal_idioma_notificaciones'] ?? ''));
            $locale = $this->normalizeLocale($branchLocale !== '' ? $branchLocale : (string) ($row['idioma_notificaciones'] ?? 'ES'));

            $companyName = trim((string) ($row['nombre_fantasia'] ?? ''));
            if ($companyName === '') {
                $companyName = trim((string) ($row['razon_social'] ?? ''));
            }
            $message = (new ReadingReminderMessageBuilder())->build(
                $locale,
                $stage,
                $name,
                $equipmentLabel,
                $url,
                $companyName,
                $effectivePilot,
                $realPhone !== null,
            );

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
                    ? ($effectivePilot
                        ? 'Prueba dirigida/piloto activa pero no hay un teléfono piloto válido configurado.'
                        : 'El chofer asignado no tiene un celular válido para WhatsApp.')
                    : null,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
            $whatsAppInserted = $this->db->affectedRows() > 0;

            if ($stage === 'friday' && $testSuffix === '') {
                $this->notifyMaintenanceResponsible(
                    $companyId,
                    $branchId > 0 ? $branchId : null,
                    $equipmentId,
                    $employeeId,
                    $name,
                    $equipmentLabel,
                    $weekKey,
                    $now,
                );
            }

            if ($phone !== null && $whatsAppInserted) {
                $scheduled++;
            }
            if ($maxScheduled !== null && $limitedCandidates >= max(1, $maxScheduled)) {
                break;
            }
        }

        return $scheduled;
    }

    private function weeklyReadingStage(\DateTimeInterface $now): ?string
    {
        $time = trim((string) env('alerts.weeklyReadingReminderTime', '08:00'));
        if (preg_match('/^(\\d{1,2}):(\\d{2})$/', $time, $matches) !== 1) {
            $matches = [null, '08', '00'];
        }
        $hour = max(0, min(23, (int) ($matches[1] ?? 8)));
        $minute = max(0, min(59, (int) ($matches[2] ?? 0)));
        $monday = \DateTimeImmutable::createFromInterface($now)->modify('monday this week')->setTime($hour, $minute);
        $wednesday = $monday->modify('+2 days');
        $friday = $monday->modify('+4 days');

        if ($now >= $friday) {
            return 'friday';
        }
        if ($now >= $wednesday) {
            return 'wednesday';
        }
        if ($now >= $monday) {
            return 'initial';
        }

        return null;
    }

    private function isCurrentDriverAssignment(int $companyId, int $equipmentId, int $employeeId): bool
    {
        if ($companyId <= 0 || $equipmentId <= 0 || $employeeId <= 0) {
            return false;
        }

        return $this->db->table('employee_equipment_assignments')
            ->where('empresa_id', $companyId)
            ->where('equipo_id', $equipmentId)
            ->where('empleado_id', $employeeId)
            ->where('rol', 'CHOFER')
            ->where('fecha_hasta', null)
            ->countAllResults() > 0;
    }

    private function hasKilometerReadingSince(int $companyId, int $equipmentId, \DateTimeInterface $since): bool
    {
        return $this->db->table('lecturas_equipo')
            ->where('empresa_id', $companyId)
            ->where('equipo_id', $equipmentId)
            ->where('anulada', 0)
            ->where('kilometraje IS NOT NULL', null, false)
            ->where('fecha_lectura >=', $since->format('Y-m-d H:i:s'))
            ->countAllResults() > 0;
    }

    private function notifyMaintenanceResponsible(
        int $companyId,
        ?int $branchId,
        int $equipmentId,
        int $employeeId,
        string $driverName,
        string $equipmentLabel,
        string $weekKey,
        \DateTimeInterface $now,
    ): void {
        $responsibles = $this->db->table('usuarios u')
            ->select('DISTINCT u.id', false)
            ->join('usuario_roles ur', 'ur.usuario_id = u.id', 'inner')
            ->join('roles r', 'r.id = ur.rol_id', 'inner')
            ->where('u.empresa_id', $companyId)
            ->where('u.activo', 1)
            ->where('u.deleted_at', null)
            ->where('r.nombre', 'Responsable de mantenimiento')
            ->get()
            ->getResultArray();
        if ($responsibles === []) {
            return;
        }

        $lastReading = $this->db->table('lecturas_equipo')
            ->select('kilometraje, fecha_lectura')
            ->where('empresa_id', $companyId)
            ->where('equipo_id', $equipmentId)
            ->where('anulada', 0)
            ->where('kilometraje IS NOT NULL', null, false)
            ->orderBy('fecha_lectura', 'DESC')
            ->limit(1)
            ->get()
            ->getRowArray();

        $readingText = 'sin lecturas previas';
        if ($lastReading !== null) {
            $lastAt = new \DateTimeImmutable((string) $lastReading['fecha_lectura']);
            $days = max(0, (int) $lastAt->diff(\DateTimeImmutable::createFromInterface($now))->format('%a'));
            $readingText = (int) $lastReading['kilometraje'] . ' km el ' . $lastAt->format('d/m/Y')
                . ' (' . $days . ' día' . ($days === 1 ? '' : 's') . ')';
        }

        $driver = trim($driverName) === '' ? 'Chofer #' . $employeeId : trim($driverName);
        $summary = $driver . ' no registró el kilometraje semanal de ' . $equipmentLabel
            . '. Última lectura: ' . $readingText . '.';
        $createdAt = $now->format('Y-m-d H:i:s');

        foreach ($responsibles as $responsible) {
            $userId = (int) ($responsible['id'] ?? 0);
            if ($userId <= 0) {
                continue;
            }
            $key = 'lectura.semanal.incumplida:empresa:' . $companyId
                . ':equipo:' . $equipmentId . ':chofer:' . $employeeId
                . ':semana:' . $weekKey . ':usuario:' . $userId;

            if ($this->db->table('notificaciones')->where('clave_evento', $key)->countAllResults() > 0) {
                continue;
            }

            $this->db->table('notificaciones')->ignore(true)->insert([
                'empresa_id' => $companyId,
                'sucursal_id' => $branchId,
                'usuario_id' => $userId,
                'tipo_evento' => 'equipo.lectura_semanal_incumplida',
                'severidad' => 'WARNING',
                'titulo' => 'Kilometraje semanal pendiente',
                'resumen' => $summary,
                'entidad_tipo' => 'equipo',
                'entidad_id' => (string) $equipmentId,
                'url' => '/mantenimiento/equipos/' . $equipmentId,
                'clave_evento' => $key,
                'estado' => 'PENDIENTE',
                'created_at' => $createdAt,
            ]);
        }
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
        $dispatchLimit = max(1, min(1000, $limit));
        // Leemos por delante del batch real porque una entrega semanal puede quedar
        // obsoleta por un cambio de chofer. Si sólo leyéramos exactamente el batch,
        // esas filas viejas podrían demorar el recordatorio de la unidad actual.
        $scanLimit = min(1000, max($dispatchLimit, $dispatchLimit * 10));

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
            ->limit($scanLimit)
            ->get()
            ->getResultArray();

        if ($rows === []) {
            return [];
        }

        // Segunda defensa: aunque una fila duplicada hubiera quedado en cola por
        // datos históricos o una carrera, nunca devolver dos recordatorios semanales
        // equivalentes para despacho. Si ya existe uno aceptado, o ya elegimos uno
        // equivalente en este mismo batch, el duplicado se omite con auditoría.
        $seenWeekly = [];
        $dispatchable = [];
        foreach ($rows as $row) {
            if (count($dispatchable) >= $dispatchLimit) {
                break;
            }

            if ((string) ($row['tipo_evento'] ?? '') === 'usuario.resumen_diario') {
                $digest = $this->hydrateUserDailyDigest($row);
                if ($digest === null) {
                    $this->skipped(
                        (int) ($row['id'] ?? 0),
                        'Sin notificaciones pendientes para incluir en el resumen diario.',
                    );
                    continue;
                }
                $dispatchable[] = $digest;
                continue;
            }

            if ((string) ($row['tipo_evento'] ?? '') !== 'equipo.recordatorio_lectura_semanal') {
                $dispatchable[] = $row;
                continue;
            }

            $deliveryId = (int) ($row['id'] ?? 0);
            $deliveryKey = (string) ($row['clave_entrega'] ?? '');
            $companyId = (int) ($row['empresa_id'] ?? 0);
            $equipmentId = (int) ($row['equipo_id'] ?? 0);
            $employeeId = (int) ($row['empleado_id'] ?? 0);

            // La cola conserva la foto del destinatario al momento de programar.
            // Antes de enviar hay que validar la realidad actual: si el chofer cambió
            // de unidad, el mensaje viejo no puede salir aunque siga PENDIENTE/REINTENTO.
            if (! $this->isCurrentDriverAssignment($companyId, $equipmentId, $employeeId)) {
                $this->skipped(
                    $deliveryId,
                    'Chofer ya no asignado al equipo al momento del despacho.',
                );
                continue;
            }

            if ((str_starts_with($deliveryKey, 'seguimiento_lectura_miercoles:')
                    || str_starts_with($deliveryKey, 'seguimiento_lectura_viernes:'))
                && $this->hasKilometerReadingSince(
                    (int) ($row['empresa_id'] ?? 0),
                    (int) ($row['equipo_id'] ?? 0),
                    new \DateTimeImmutable((string) ($row['created_at'] ?? 'now')),
                )) {
                $this->skipped(
                    $deliveryId,
                    'Regularizado: se registró kilometraje después de programar el seguimiento semanal.',
                );
                continue;
            }
            $testPosition = strpos($deliveryKey, ':prueba:');
            $isTest = $testPosition !== false;
            $baseKey = $isTest ? substr($deliveryKey, 0, $testPosition) : $deliveryKey;
            $dedupeKey = ($isTest ? 'test|' : 'prod|') . $baseKey;

            $accepted = $this->db->table('notificacion_whatsapp_entregas')
                ->where('tipo_evento', 'equipo.recordatorio_lectura_semanal')
                ->whereIn('estado', ['PENDIENTE_CONFIRMACION', 'ACEPTADA'])
                ->where('id !=', $deliveryId);
            if ($isTest) {
                $accepted->like('clave_entrega', $baseKey . ':prueba:', 'after');
            } else {
                $accepted->where('clave_entrega', $baseKey);
            }

            if (isset($seenWeekly[$dedupeKey]) || $accepted->countAllResults() > 0) {
                $this->skipped(
                    $deliveryId,
                    'Omitido por blindaje anti-duplicado: ya existe un recordatorio semanal equivalente enviado o seleccionado.',
                );
                continue;
            }

            $seenWeekly[$dedupeKey] = true;
            $dispatchable[] = $row;
        }
        $rows = $dispatchable;

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
        $normalizedStatus = strtolower(trim($status));
        $final = in_array($normalizedStatus, ['accepted', 'delivered', 'read'], true);

        $this->db->table('notificacion_whatsapp_entregas')->where('id', $deliveryId)->update([
            'estado' => $final ? 'ACEPTADA' : 'PENDIENTE_CONFIRMACION',
            'gateway_message_id' => $messageId,
            'gateway_status' => $normalizedStatus === '' ? 'queued' : $normalizedStatus,
            'enviada_en' => $final ? $now : null,
            'ultimo_error' => null,
            'updated_at' => $now,
        ]);
    }

    public function awaitingConfirmation(int $limit): array
    {
        return $this->db->table('notificacion_whatsapp_entregas')
            ->where('estado', 'PENDIENTE_CONFIRMACION')
            ->where('gateway_message_id IS NOT NULL', null, false)
            ->orderBy('updated_at', 'ASC')
            ->limit(max(1, min(1000, $limit)))
            ->get()
            ->getResultArray();
    }

    public function reconcileStatus(
        int $deliveryId,
        string $status,
        ?string $providerMessageId = null,
        ?string $error = null,
    ): void {
        $normalizedStatus = strtolower(trim($status));
        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $updates = [
            'gateway_status' => $normalizedStatus,
            'provider_message_id' => $providerMessageId,
            'updated_at' => $now,
        ];

        if (in_array($normalizedStatus, ['accepted', 'delivered', 'read'], true)) {
            $updates['estado'] = 'ACEPTADA';
            $updates['enviada_en'] = $now;
            $updates['ultimo_error'] = null;
            $updates['proximo_intento'] = null;
        } elseif ($normalizedStatus === 'failed') {
            $failure = $error === null || trim($error) === ''
                ? 'Vogel WhatsApp API informó estado failed sin detalle adicional.'
                : $error;
            $updates['estado'] = 'FALLIDA';
            $updates['ultimo_error'] = mb_substr($failure, 0, 1000);
            $updates['proximo_intento'] = null;
        } else {
            $updates['estado'] = 'PENDIENTE_CONFIRMACION';
            if ($error !== null && trim($error) !== '') {
                $updates['ultimo_error'] = mb_substr($error, 0, 1000);
            }
        }

        $this->db->table('notificacion_whatsapp_entregas')
            ->where('id', $deliveryId)
            ->update($updates);

        if ($normalizedStatus === 'failed') {
            $this->notifyMaintenanceResponsibleOfWhatsAppFailure(
                $deliveryId,
                (string) ($updates['ultimo_error'] ?? 'Fallo de entrega WhatsApp.'),
            );
        }
    }

    private function notifyMaintenanceResponsibleOfWhatsAppFailure(int $deliveryId, string $error): void
    {
        $delivery = $this->db->table('notificacion_whatsapp_entregas n')
            ->select('n.empresa_id, n.equipo_id, n.empleado_id, n.telefono, n.external_ref')
            ->select('emp.nombre, emp.apellido')
            ->select('e.codigo equipo_codigo, e.sucursal_id')
            ->join('empleados emp', 'emp.id = n.empleado_id AND emp.empresa_id = n.empresa_id', 'left')
            ->join('equipos e', 'e.id = n.equipo_id AND e.empresa_id = n.empresa_id', 'left')
            ->where('n.id', $deliveryId)
            ->get()
            ->getRowArray();
        if ($delivery === null) {
            return;
        }

        $companyId = (int) ($delivery['empresa_id'] ?? 0);
        if ($companyId <= 0) {
            return;
        }
        $admins = $this->db->table('usuarios u')
            ->select('DISTINCT u.id', false)
            ->join('usuario_roles ur', 'ur.usuario_id = u.id', 'inner')
            ->join('roles r', 'r.id = ur.rol_id', 'inner')
            ->where('u.empresa_id', $companyId)
            ->where('u.activo', 1)
            ->where('u.deleted_at', null)
            ->where('r.nombre', 'Responsable de mantenimiento')
            ->get()
            ->getResultArray();

        $driver = trim((string) ($delivery['nombre'] ?? '') . ' ' . (string) ($delivery['apellido'] ?? ''));
        $equipment = trim((string) ($delivery['equipo_codigo'] ?? ''));
        $phone = trim((string) ($delivery['telefono'] ?? ''));
        $summary = 'Falló el envío WhatsApp'
            . ($driver === '' ? '' : ' a ' . $driver)
            . ($equipment === '' ? '' : ' para el equipo ' . $equipment)
            . ($phone === '' ? '' : ' (destino ' . $phone . ')')
            . '. Error: ' . mb_substr($error, 0, 500);
        $createdAt = $this->clock->now()->format('Y-m-d H:i:s');

        foreach ($admins as $admin) {
            $userId = (int) ($admin['id'] ?? 0);
            if ($userId <= 0) {
                continue;
            }
            $key = 'whatsapp.fallido:entrega:' . $deliveryId . ':usuario:' . $userId;
            if ($this->db->table('notificaciones')->where('clave_evento', $key)->countAllResults() > 0) {
                continue;
            }
            $this->db->table('notificaciones')->ignore(true)->insert([
                'empresa_id' => $companyId,
                'sucursal_id' => isset($delivery['sucursal_id']) ? (int) $delivery['sucursal_id'] : null,
                'usuario_id' => $userId,
                'tipo_evento' => 'whatsapp.entrega_fallida',
                'severidad' => 'WARNING',
                'titulo' => 'Falló una notificación WhatsApp',
                'resumen' => $summary,
                'entidad_tipo' => 'empleado',
                'entidad_id' => (string) ((int) ($delivery['empleado_id'] ?? 0)),
                'url' => '/empleados',
                'clave_evento' => $key,
                'estado' => 'PENDIENTE',
                'created_at' => $createdAt,
            ]);
        }
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
    private function message(NotifiableEvent $event, array $driver, bool $pilotEnabled, bool $realPhoneValid, string $companyName): string
    {
        $name = trim((string) ($driver['nombre'] ?? '') . ' ' . (string) ($driver['apellido'] ?? ''));
        $greeting = $name === '' ? 'Hola.' : 'Hola ' . $name . '.';
        $pilotHeader = $pilotEnabled
            ? "🧪 *PRUEBA CONTROLADA · NO ENVIADO AL DESTINATARIO REAL*\n"
                . "*Destinatario previsto:* " . ($name === '' ? 'Chofer asignado' : $name) . "\n"
                . "*Teléfono real:* " . ($realPhoneValid ? 'configurado' : 'no válido o no cargado') . "\n\n"
            : '';

        return $pilotHeader
            . "*" . ($companyName !== '' ? $companyName : 'Empresa') . "* · Mantenimiento\n\n"
            . $greeting . "\n\n"
            . "⚠️ *" . trim($event->title()) . "*\n"
            . rtrim(trim($event->summary()), ".") . ".\n\n"
            . "Por favor, revisá la situación del equipo y coordiná la regularización con el responsable.\n\n"
            . "🌐 *Vogel Consultoría · Mantenimiento*\n"
            . "https://vogelconsultoria.com.ar/mantenimiento\n\n"
            . "_Aviso automático del Sistema de Mantenimiento._";
    }

    /** @param array<string,mixed> $row */
    private function hydrateUserDailyDigest(array $row): ?array
    {
        $userId = (int) ($row['usuario_id'] ?? 0);
        $companyId = (int) ($row['empresa_id'] ?? 0);
        if ($userId <= 0 || $companyId <= 0) {
            return null;
        }

        $user = $this->db->table('usuarios u')
            ->select('u.nombre, u.telefono, co.razon_social, co.nombre_fantasia')
            ->join('empresas co', 'co.id = u.empresa_id', 'inner')
            ->where('u.id', $userId)
            ->where('u.empresa_id', $companyId)
            ->where('u.activo', 1)
            ->where('u.deleted_at', null)
            ->get()
            ->getRowArray();
        if ($user === null) {
            return null;
        }

        // Las notificaciones semanales persisten hasta que alguien las lee.
        // Una lectura posterior puede regularizar la deuda antes de enviar el digest.
        (new WeeklyReadingNotificationRevalidator($this->db))
            ->revalidate($companyId, $userId, $this->clock->now());

        $count = $this->db->table('notificaciones')
            ->where('empresa_id', $companyId)
            ->where('usuario_id', $userId)
            ->where('estado', 'PENDIENTE')
            ->countAllResults();
        if ($count <= 0) {
            return null;
        }

        $items = $this->db->table('notificaciones')
            ->select('titulo, resumen, severidad')
            ->where('empresa_id', $companyId)
            ->where('usuario_id', $userId)
            ->where('estado', 'PENDIENTE')
            ->orderBy("FIELD(severidad, 'CRITICAL', 'WARNING', 'INFO')", '', false)
            ->orderBy('id', 'ASC')
            ->limit(12)
            ->get()
            ->getResultArray();
        if ($items === []) {
            return null;
        }

        $settings = $this->settings->get();
        $pilotEnabled = (bool) ($settings['whatsapp_pilot_enabled'] ?? true);
        $realPhone = $this->gateway->normalizePhone((string) ($user['telefono'] ?? ''));
        $name = trim((string) ($user['nombre'] ?? ''));
        $firstName = trim((string) preg_replace('/\s+.*/u', '', $name));
        $companyName = trim((string) ($user['nombre_fantasia'] ?? ''));
        if ($companyName === '') {
            $companyName = trim((string) ($user['razon_social'] ?? 'Empresa'));
        }

        $body = '';
        foreach ($items as $item) {
            $severity = strtoupper(trim((string) ($item['severidad'] ?? 'INFO')));
            $icon = $severity === 'CRITICAL' ? '🔴' : ($severity === 'WARNING' ? '🟠' : '🔵');
            $title = trim((string) ($item['titulo'] ?? 'Aviso'));
            $summary = trim((string) ($item['resumen'] ?? ''));
            if (mb_strlen($summary) > 180) {
                $summary = rtrim(mb_substr($summary, 0, 177)) . '...';
            }
            $body .= $icon . ' *' . $title . "*\n";
            if ($summary !== '') {
                $body .= $summary . "\n";
            }
            $body .= "\n";
        }

        if ($count > count($items)) {
            $body .= '➕ ' . ($count - count($items)) . " tema(s) adicional(es) en el sistema.\n\n";
        }

        $pilotHeader = $pilotEnabled
            ? "🧪 *PRUEBA CONTROLADA · NO ENVIADO AL DESTINATARIO REAL*\n"
                . '*Destinatario previsto:* ' . ($name === '' ? 'Usuario del sistema' : $name) . "\n"
                . '*Teléfono real:* ' . ($realPhone === null ? 'no válido o no cargado' : 'configurado') . "\n\n"
            : '';

        $message = $pilotHeader
            . '*' . ($companyName === '' ? 'Empresa' : $companyName) . " · Mantenimiento*\n\n"
            . ($firstName === '' ? 'Hola 👋' : 'Hola ' . $firstName . ' 👋') . "\n"
            . '*Resumen diario · ' . $this->clock->now()->format('d/m/Y') . "*\n\n"
            . "Estos son los temas que requieren atención:\n\n"
            . $body
            . "Revisalos en el sistema de mantenimiento:\n"
            . base_url('notificaciones') . "\n\n"
            . '_Este resumen se envía una sola vez por día, de lunes a viernes._';

        $this->db->table('notificacion_whatsapp_entregas')
            ->where('id', (int) ($row['id'] ?? 0))
            ->update([
                'mensaje' => $message,
                'updated_at' => $this->clock->now()->format('Y-m-d H:i:s'),
            ]);
        $row['mensaje'] = $message;

        return $row;
    }

    private function normalizeLocale(string $locale): string
    {
        return strtoupper(trim($locale)) === 'PT' ? 'PT' : 'ES';
    }
}
