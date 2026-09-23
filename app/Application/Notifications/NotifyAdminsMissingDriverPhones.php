<?php

declare(strict_types=1);

namespace App\Application\Notifications;

use App\Application\Notifications\Port\NotificationClock;
use App\Application\Notifications\Port\NotificationRepository;
use App\Application\Notifications\Port\WhatsAppNotificationGateway;
use App\Domain\Notifications\NotifiableEvent;
use App\Domain\Notifications\Notification;
use App\Domain\Notifications\NotificationSeverity;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;
use DateTimeInterface;

final readonly class NotifyAdminsMissingDriverPhones
{
    public function __construct(
        private NotificationRepository $notifications,
        private NotificationClock $clock,
        private WhatsAppNotificationGateway $whatsApp,
        private BaseConnection $db,
    ) {
    }

    /** @return array{companies:int,drivers:int,notifications:int,updated:int,duplicates:int} */
    public function execute(bool $force = false): array
    {
        $summary = ['companies' => 0, 'drivers' => 0, 'notifications' => 0, 'updated' => 0, 'duplicates' => 0];
        $now = $this->clock->now();

        if (! $force && ! $this->weeklyReminderIsDue($now)) {
            return $summary;
        }

        $rows = $this->db->table('employee_equipment_assignments a')
            ->select('a.empresa_id, a.empleado_id, emp.nombre, emp.apellido, emp.telefono')
            ->select('e.id equipo_id, e.codigo equipo_codigo, e.patente')
            ->join('empleados emp', 'emp.id = a.empleado_id AND emp.empresa_id = a.empresa_id', 'inner')
            ->join('equipos e', 'e.id = a.equipo_id AND e.empresa_id = a.empresa_id', 'inner')
            ->join('empresas co', 'co.id = a.empresa_id', 'inner')
            ->where('a.rol', 'CHOFER')
            ->where('a.fecha_hasta', null)
            ->where('emp.activo', 1)
            ->where('emp.deleted_at', null)
            ->where('e.estado', 'ACTIVO')
            ->where('e.deleted_at', null)
            ->where('co.estado', 1)
            ->where('co.deleted_at', null)
            ->where('co.notificaciones_whatsapp_habilitadas', 1)
            ->orderBy('a.id', 'DESC')
            ->get()
            ->getResultArray();

        $missingByCompany = [];
        $seenAssignments = [];
        foreach ($rows as $row) {
            $companyId = (int) ($row['empresa_id'] ?? 0);
            $employeeId = (int) ($row['empleado_id'] ?? 0);
            $equipmentId = (int) ($row['equipo_id'] ?? 0);
            $key = $companyId . ':' . $employeeId . ':' . $equipmentId;
            if ($companyId <= 0 || $employeeId <= 0 || $equipmentId <= 0 || isset($seenAssignments[$key])) {
                continue;
            }
            $seenAssignments[$key] = true;

            $rawPhone = trim((string) ($row['telefono'] ?? ''));
            $category = $this->phoneObservationCategory($rawPhone);
            if ($category === null) {
                continue;
            }

            $name = trim((string) ($row['nombre'] ?? '') . ' ' . (string) ($row['apellido'] ?? ''));
            if ($name === '') {
                $name = 'Empleado #' . $employeeId;
            }
            $equipment = trim((string) ($row['equipo_codigo'] ?? ''));
            $plate = trim((string) ($row['patente'] ?? ''));
            if ($plate !== '' && mb_strtoupper($plate) !== mb_strtoupper($equipment)) {
                $equipment .= ($equipment === '' ? '' : ' · ') . $plate;
            }
            if ($equipment === '') {
                $equipment = 'Equipo #' . $equipmentId;
            }

            $missingByCompany[$companyId][$employeeId] = [
                'name' => $name,
                'equipment' => $equipment,
                'phone' => $rawPhone === '' ? '(sin teléfono)' : $rawPhone,
                'category' => $category['code'],
                'reason' => $category['label'],
            ];
        }

        $weekKey = $now->format('o-\\WW');
        foreach ($missingByCompany as $companyId => $drivers) {
            if ($drivers === []) {
                continue;
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

            if ($admins === []) {
                continue;
            }

            $items = array_values($drivers);
            $summary['companies']++;
            $summary['drivers'] += count($items);

            $counts = [];
            foreach ($items as $item) {
                $counts[$item['category']] = ($counts[$item['category']] ?? 0) + 1;
            }
            $labels = [
                'missing' => 'sin teléfono',
                'local' => 'número local sin código internacional',
                'ar_without_9' => 'Argentina sin 9 o formato incompleto',
                'br_incomplete' => 'Brasil con formato incompleto',
                'cl_incomplete' => 'Chile con formato incompleto',
                'invalid' => 'otro formato internacional inválido',
            ];
            $parts = [];
            foreach ($labels as $code => $label) {
                if (($counts[$code] ?? 0) > 0) {
                    $parts[] = $counts[$code] . ' ' . $label;
                }
            }

            $shown = array_slice($items, 0, 12);
            $detail = implode('; ', array_map(
                static fn (array $item): string => $item['name']
                    . ' (' . $item['equipment']
                    . ', tel. ' . $item['phone']
                    . ', ' . $item['reason'] . ')',
                $shown,
            ));
            $remaining = count($items) - count($shown);
            if ($remaining > 0) {
                $detail .= '; +' . $remaining . ' más';
            }

            $title = 'Revisión de celulares para WhatsApp';
            $eventSummary = 'Se detectaron ' . count($items)
                . ' chofer(es) que requieren corrección de teléfono. Resumen: '
                . implode(', ', $parts)
                . '. Detalle: ' . $detail
                . '. Cargá el número completo en formato internacional y sólo dígitos (Argentina 549..., Brasil 55..., Chile 56...). Mientras siga observado no se enviarán recordatorios de km ni avisos de vencimientos.';

            $event = new NotifiableEvent(
                (int) $companyId,
                null,
                'chofer.telefono_faltante',
                NotificationSeverity::WARNING,
                $title,
                $eventSummary,
                'empresa',
                (string) $companyId,
                'chofer.telefono_faltante:empresa:' . $companyId . ':semana:' . $weekKey,
                '/empleados',
                DateTimeImmutable::createFromInterface($now),
            );

            foreach ($admins as $admin) {
                $userId = (int) $admin['id'];
                $notification = Notification::forRecipient($event, $userId);
                if ($this->notifications->createIfAbsent($notification) !== null) {
                    $summary['notifications']++;
                    continue;
                }

                $key = $notification->idempotencyKey();
                $existing = $this->db->table('notificaciones')
                    ->select('id')
                    ->where('empresa_id', $companyId)
                    ->where('usuario_id', $userId)
                    ->where('clave_evento', $key)
                    ->get()
                    ->getRowArray();
                if ($existing === null) {
                    $summary['duplicates']++;
                    continue;
                }

                $this->db->table('notificaciones')
                    ->where('id', (int) $existing['id'])
                    ->update([
                        'titulo' => $title,
                        'resumen' => $eventSummary,
                        'estado' => 'PENDIENTE',
                        'leida_en' => null,
                        'updated_at' => $now->format('Y-m-d H:i:s'),
                    ]);
                $summary['updated']++;
            }
        }

        return $summary;
    }

    /** @return array{code:string,label:string}|null */
    private function phoneObservationCategory(string $phone): ?array
    {
        $phone = trim($phone);
        if ($phone === '') {
            return ['code' => 'missing', 'label' => 'sin teléfono'];
        }

        if ($this->whatsApp->normalizePhone($phone) !== null) {
            return null;
        }

        if (preg_match('/^[0-9]{10}$/', $phone) === 1) {
            return ['code' => 'local', 'label' => 'número local sin código internacional'];
        }

        if (preg_match('/^54[0-9]+$/', $phone) === 1 && ! str_starts_with($phone, '549')) {
            return ['code' => 'ar_without_9', 'label' => 'Argentina sin 9 o formato incompleto'];
        }

        if (preg_match('/^55[0-9]+$/', $phone) === 1) {
            return ['code' => 'br_incomplete', 'label' => 'Brasil con formato incompleto'];
        }

        if (preg_match('/^56[0-9]+$/', $phone) === 1) {
            return ['code' => 'cl_incomplete', 'label' => 'Chile con formato incompleto'];
        }

        return ['code' => 'invalid', 'label' => 'otro formato internacional inválido'];
    }

    private function weeklyReminderIsDue(DateTimeInterface $now): bool
    {
        $day = max(1, min(7, (int) env('alerts.weeklyReadingReminderDay', 1)));
        $time = trim((string) env('alerts.weeklyReadingReminderTime', '08:00'));
        if (preg_match('/^(\\d{1,2}):(\\d{2})$/', $time, $matches) !== 1) {
            $matches = [null, '08', '00'];
        }

        $hour = max(0, min(23, (int) ($matches[1] ?? 8)));
        $minute = max(0, min(59, (int) ($matches[2] ?? 0)));
        $weekStart = DateTimeImmutable::createFromInterface($now)
            ->modify('monday this week')
            ->setTime(0, 0);
        $dueAt = $weekStart
            ->modify('+' . ($day - 1) . ' days')
            ->setTime($hour, $minute);

        return $now >= $dueAt;
    }
}
