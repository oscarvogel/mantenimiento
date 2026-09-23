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
        $effectivePilot = $pilotEnabled || $forcePilotDestination;
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

    public function scheduleWeeklyReadingReminders(bool $force = false, ?string $testKey = null, ?int $maxScheduled = null, ?string $forcedStage = null, bool $simulateMissingReading = false, ?int $onlyEquipmentId = null, bool $forcePilotDestination = false): int
    {
        if (! $this->gateway->available()) {
            return 0;
        }

        $settings = $this->settings->get();
        $pilotEnabled = (bool) ($settings['whatsapp_pilot_enabled'] ?? true);
        $pilotPhone = $this->gateway->normalizePhone((string) ($settings['whatsapp_pilot_phone'] ?? ''));
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
            ->select('co.notificaciones_whatsapp_habilitadas, co.whatsapp_instance_id, co.idioma_notificaciones')
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
            if ($onlyEquipmentId !== null && $equipmentId !== $onlyEquipmentId) {
                continue;
            }
            if ($equipmentId <= 0 || isset($seenEquipment[$equipmentId])) {
                continue;
            }
            $seenEquipment[$equipmentId] = true;

            $employeeId = (int) $row['empleado_id'];
            $companyId = (int) $row['empresa_id'];
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
            $pilotHeader = $effectivePilot
                ? ($locale === 'PT'
                    ? "🧪 *TESTE CONTROLADO · NÃO ENVIADO AO DESTINATÁRIO REAL*\n"
                        . "*Destinatário previsto:* " . ($name === '' ? 'Motorista atribuído' : $name) . "\n"
                        . "*Telefone real:* " . ($realPhone === null ? 'inválido ou não informado' : 'configurado') . "\n\n"
                    : "🧪 *PRUEBA CONTROLADA · NO ENVIADO AL DESTINATARIO REAL*\n"
                        . "*Destinatario previsto:* " . ($name === '' ? 'Chofer asignado' : $name) . "\n"
                        . "*Teléfono real:* " . ($realPhone === null ? 'no válido o no cargado' : 'configurado') . "\n\n")
                : '';

            $message = $this->weeklyReadingMessage($locale, $stage, $pilotHeader, $name, $equipmentLabel, $url);

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

    private function weeklyReadingMessage(string $locale, string $stage, string $pilotHeader, string $name, string $equipmentLabel, string $url): string
    {
        if ($locale === 'PT') {
            $title = match ($stage) {
                'wednesday' => 'Segundo lembrete de quilometragem',
                'friday' => 'Aviso final de quilometragem',
                default => 'Lembrete semanal de quilometragem',
            };
            $intro = $stage === 'initial'
                ? 'Por favor, informe a quilometragem atual'
                : 'Ainda não registramos a quilometragem desta semana. Por favor, informe a quilometragem atual';

            return $pilotHeader
                . "*Vogel Consultoría · Manutenção*\n\n"
                . ($name === '' ? 'Olá.' : 'Olá ' . $name . '.') . "\n\n"
                . "🚛 *" . $title . "*\n"
                . $intro . " de *" . $equipmentLabel . "*.\n\n"
                . "👉 *Informar quilometragem:*\n" . $url . "\n\n"
                . "Não é necessário fazer login: o link corresponde ao acesso público do equipamento.\n\n"
                . "🌐 *Vogel Consultoría · Manutenção*\n"
                . "https://vogelconsultoria.com.ar/mantenimiento\n\n"
                . "_Aviso automático do Sistema de Manutenção._";
        }

        $title = match ($stage) {
            'wednesday' => 'Segundo recordatorio de kilometraje',
            'friday' => 'Aviso final de kilometraje',
            default => 'Recordatorio semanal de kilometraje',
        };
        $intro = $stage === 'initial'
            ? 'Por favor, cargá el kilometraje actual'
            : 'Todavía no registramos el kilometraje de esta semana. Por favor, cargá el kilometraje actual';

        return $pilotHeader
            . "*Vogel Consultoría · Mantenimiento*\n\n"
            . ($name === '' ? 'Hola.' : 'Hola ' . $name . '.') . "\n\n"
            . "🚛 *" . $title . "*\n"
            . $intro . " de *" . $equipmentLabel . "*.\n\n"
            . "👉 *Cargar kilometraje:*\n" . $url . "\n\n"
            . "No necesitás iniciar sesión: el enlace corresponde al acceso público del equipo.\n\n"
            . "🌐 *Vogel Consultoría · Mantenimiento*\n"
            . "https://vogelconsultoria.com.ar/mantenimiento\n\n"
            . "_Aviso automático del Sistema de Mantenimiento._";
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

        // Segunda defensa: aunque una fila duplicada hubiera quedado en cola por
        // datos históricos o una carrera, nunca devolver dos recordatorios semanales
        // equivalentes para despacho. Si ya existe uno aceptado, o ya elegimos uno
        // equivalente en este mismo batch, el duplicado se omite con auditoría.
        $seenWeekly = [];
        $dispatchable = [];
        foreach ($rows as $row) {
            if ((string) ($row['tipo_evento'] ?? '') !== 'equipo.recordatorio_lectura_semanal') {
                $dispatchable[] = $row;
                continue;
            }

            $deliveryId = (int) ($row['id'] ?? 0);
            $deliveryKey = (string) ($row['clave_entrega'] ?? '');

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
