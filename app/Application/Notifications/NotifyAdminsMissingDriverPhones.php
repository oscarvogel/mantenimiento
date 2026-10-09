<?php

declare(strict_types=1);

namespace App\Application\Notifications;

use App\Application\Notifications\Port\DriverPhoneAuditReadModel;
use App\Application\Notifications\Port\NotificationClock;
use App\Application\Notifications\Port\NotificationRepository;
use App\Application\Notifications\Port\WhatsAppNotificationGateway;
use App\Domain\Notifications\NotifiableEvent;
use App\Domain\Notifications\Notification;
use App\Domain\Notifications\NotificationSeverity;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * Auditoría de celulares de choferes con observación para WhatsApp.
 *
 * Reglas que este caso de uso garantiza (#473):
 *
 * - El aviso describe SIEMPRE las irregularidades vigentes. Si de tres choferes
 *   se corrigen dos, el contenido pasa a listar sólo al que queda pendiente.
 * - Cuando ya no queda ninguna irregularidad, el aviso se REGULARIZA: deja de
 *   estar pendiente y sale de la bandeja, conservando su texto original como
 *   auditoría. No se borra nada.
 * - La clave lógica es estable por empresa, sin semana: un aviso por empresa y
 *   no uno por semana. Así una corrección no deja avisos viejos flotando ni
 *   genera un aviso nuevo cada lunes para el mismo problema.
 * - Reejecutar el cron o la auditoría manual no duplica avisos, no reabre los
 *   ya regularizados, no cambia estados sin motivo y no envía WhatsApp.
 */
final readonly class NotifyAdminsMissingDriverPhones
{
    private const EVENT_TYPE = 'chofer.telefono_faltante';

    /** Ancho de `notificaciones.resumen`. */
    private const RESUM_MAX_LENGTH = 500;

    /** @var array<string, string> */
    private const CATEGORY_LABELS = [
        'missing' => 'sin teléfono',
        'local' => 'número local sin código internacional',
        'ar_without_9' => 'Argentina sin 9 o formato incompleto',
        'br_incomplete' => 'Brasil con formato incompleto',
        'cl_incomplete' => 'Chile con formato incompleto',
        'invalid' => 'otro formato internacional inválido',
    ];

    public function __construct(
        private NotificationRepository $notifications,
        private NotificationClock $clock,
        private WhatsAppNotificationGateway $whatsApp,
        private DriverPhoneAuditReadModel $auditReadModel,
    ) {
    }

    /** @return array{companies:int,drivers:int,notifications:int,updated:int,regularized:int,duplicates:int} */
    public function execute(bool $force = false): array
    {
        $summary = ['companies' => 0, 'drivers' => 0, 'notifications' => 0, 'updated' => 0, 'regularized' => 0, 'duplicates' => 0];
        $now = $this->clock->now();

        if (! $force && ! $this->weeklyReminderIsDue($now)) {
            return $summary;
        }

        $audited = $this->audit();

        // Se recorren también las empresas con avisos pendientes que ya no
        // aparecen en el relevamiento (sin choferes vigentes, WhatsApp
        // deshabilitado o empresa dada de baja). Sin esto, sus avisos
        // quedarían pidiendo una corrección que ya no aplica.
        foreach ($this->notifications->companiesWithPending(self::EVENT_TYPE) as $companyId) {
            $audited[$companyId] ??= [];
        }

        foreach ($audited as $companyId => $items) {
            if ($items === []) {
                // Sin irregularidades vigentes no queda nada que avisar: cualquier
                // aviso anterior de esta empresa pasa a REGULARIZADA.
                $summary['regularized'] += $this->notifications->regularizePending($companyId, self::EVENT_TYPE, $now);
                continue;
            }

            $adminUserIds = $this->auditReadModel->responsibleAdminUserIds($companyId);
            if ($adminUserIds === []) {
                $summary['regularized'] += $this->notifications->regularizePending($companyId, self::EVENT_TYPE, $now);
                continue;
            }

            $title = 'Revisión de celulares para WhatsApp';
            $summaryText = $this->composeSummary($items);
            $url = '/mantenimiento/empleados';
            $eventKey = $this->eventKey($companyId);

            $summary['companies']++;
            $summary['drivers'] += count($items);

            $event = new NotifiableEvent(
                $companyId,
                null,
                self::EVENT_TYPE,
                NotificationSeverity::WARNING,
                $title,
                $summaryText,
                'empresa',
                (string) $companyId,
                $eventKey,
                $url,
                DateTimeImmutable::createFromInterface($now),
            );

            foreach ($adminUserIds as $userId) {
                if ($this->notifications->createIfAbsent(Notification::forRecipient($event, $userId)) !== null) {
                    $summary['notifications']++;
                    continue;
                }

                $outcome = $this->notifications->refresh($companyId, $userId, $eventKey, $title, $summaryText, $url, $now);

                if ($outcome === NotificationRefreshOutcome::UPDATED) {
                    $summary['updated']++;
                    continue;
                }

                // UNCHANGED: el aviso ya decía exactamente esto. Volver a
                // marcarlo pendiente sería reabrir una advertencia que un humano
                // ya cerró, y no aporta información nueva.
                $summary['duplicates']++;
            }

            // Avisos del mismo tipo que sigan PENDIENTES con otra clave lógica
            // (semanas anteriores, correcciones previas) ya no describen la
            // realidad: se regularizan, conservando su contenido como auditoría.
            $summary['regularized'] += $this->notifications->regularizePending(
                $companyId,
                self::EVENT_TYPE,
                $now,
                $eventKey,
            );
        }

        return $summary;
    }

    /**
     * Recorre las empresas con choferes vigente y separa las irregularidades.
     *
     * Se incluyen también las empresas SIN irregularidades: son justamente las
     * que hay que regularizar.
     *
     * @return array<int, list<array{name:string,equipment:string,phone:string,category:string,reason:string}>>
     */
    private function audit(): array
    {
        /** @var array<int, list<array{name:string,equipment:string,phone:string,category:string,reason:string}>> $result */
        $result = [];
        $seenAssignments = [];
        foreach ($this->auditReadModel->currentAssignments() as $row) {
            $companyId = $row->companyId;
            $employeeId = $row->employeeId;
            $equipmentId = $row->equipmentId;
            $key = $companyId . ':' . $employeeId . ':' . $equipmentId;
            if ($companyId <= 0 || $employeeId <= 0 || $equipmentId <= 0 || isset($seenAssignments[$key])) {
                continue;
            }
            $seenAssignments[$key] = true;

            // La empresa queda auditada aunque este chofer esté en regla.
            $result[$companyId] ??= [];

            $rawPhone = trim((string) ($row->phone ?? ''));
            $category = $this->phoneObservationCategory($rawPhone);
            if ($category === null) {
                continue;
            }

            $name = trim($row->firstName . ' ' . $row->lastName);
            if ($name === '') {
                $name = 'Empleado #' . $employeeId;
            }

            $equipment = trim($row->equipmentCode);
            $plate = trim((string) ($row->plate ?? ''));
            if ($plate !== '' && mb_strtoupper($plate) !== mb_strtoupper($equipment)) {
                $equipment .= ($equipment === '' ? '' : ' · ') . $plate;
            }
            if ($equipment === '') {
                $equipment = 'Equipo #' . $equipmentId;
            }

            $result[$companyId][$employeeId] = [
                'name' => $name,
                'equipment' => $equipment,
                'phone' => $rawPhone === '' ? '(sin teléfono)' : $rawPhone,
                'category' => $category['code'],
                'reason' => $category['label'],
            ];
        }

        foreach ($result as $companyId => $items) {
            $result[$companyId] = array_values($items);
        }

        return $result;
    }

    /** @return array{code:string,label:string}|null */
    private function phoneObservationCategory(string $phone): ?array
    {
        $phone = trim($phone);
        if ($phone === '') {
            return ['code' => 'missing', 'label' => self::CATEGORY_LABELS['missing']];
        }

        if ($this->whatsApp->normalizePhone($phone) !== null) {
            return null;
        }

        if (preg_match('/^[0-9]{10}$/', $phone) === 1) {
            return ['code' => 'local', 'label' => self::CATEGORY_LABELS['local']];
        }

        if (preg_match('/^54[0-9]+$/', $phone) === 1 && ! str_starts_with($phone, '549')) {
            return ['code' => 'ar_without_9', 'label' => self::CATEGORY_LABELS['ar_without_9']];
        }

        if (preg_match('/^55[0-9]+$/', $phone) === 1) {
            return ['code' => 'br_incomplete', 'label' => self::CATEGORY_LABELS['br_incomplete']];
        }

        if (preg_match('/^56[0-9]+$/', $phone) === 1) {
            return ['code' => 'cl_incomplete', 'label' => self::CATEGORY_LABELS['cl_incomplete']];
        }

        return ['code' => 'invalid', 'label' => self::CATEGORY_LABELS['invalid']];
    }

    /**
     * Arma el texto del aviso.
     *
     * `notificaciones.resumen` es VARCHAR(500). Con muchas irregularidades el
     * detalle completo no entra: se muestran los primeros casos que caben y el
     * resto como "+N más". Antes, un detalle largo hacía fallar el insert en
     * modo estricto y dejaba la semana sin auditar.
     *
     * @param list<array{name:string,equipment:string,phone:string,category:string,reason:string}> $items
     */
    private function composeSummary(array $items): string
    {
        $counts = [];
        foreach ($items as $item) {
            $counts[$item['category']] = ($counts[$item['category']] ?? 0) + 1;
        }

        $parts = [];
        foreach (self::CATEGORY_LABELS as $code => $label) {
            if (($counts[$code] ?? 0) > 0) {
                $parts[] = $counts[$code] . ' ' . $label;
            }
        }

        $head = 'Se detectaron ' . count($items)
            . ' chofer(es) que requieren corrección de teléfono. Resumen: '
            . implode(', ', $parts)
            . '. Detalle: ';
        $tail = '. Cargá el número completo en formato internacional y sólo dígitos (Argentina 549..., Brasil 55..., Chile 56...). Mientras siga observado no se enviarán recordatorios de km ni avisos de vencimientos.';
        $budget = self::RESUM_MAX_LENGTH - mb_strlen($head) - mb_strlen($tail);

        $shown = [];
        $used = 0;
        foreach ($items as $item) {
            $entry = $shown === []
                ? $this->describe($item)
                : '; ' . $this->describe($item);
            if ($used + mb_strlen($entry) > $budget) {
                break;
            }
            $shown[] = $entry;
            $used += mb_strlen($entry);
        }

        $remaining = count($items) - count($shown);
        $detail = implode('', $shown);
        if ($remaining > 0) {
            $suffix = '; +' . $remaining . ' más';
            $detail = mb_strlen($detail . $suffix) <= $budget
                ? $detail . $suffix
                : mb_substr($detail, 0, max(0, $budget - mb_strlen($suffix))) . $suffix;
        }

        return $head . $detail . $tail;
    }

    /** @param array{name:string,equipment:string,phone:string,category:string,reason:string} $item */
    private function describe(array $item): string
    {
        return $item['name']
            . ' (' . $item['equipment']
            . ', tel. ' . $item['phone']
            . ', ' . $item['reason'] . ')';
    }

    private function eventKey(int $companyId): string
    {
        return self::EVENT_TYPE . ':empresa:' . $companyId;
    }

    private function weeklyReminderIsDue(DateTimeInterface $now): bool
    {
        $day = max(1, min(7, (int) env('alerts.weeklyReadingReminderDay', 1)));
        $time = trim((string) env('alerts.weeklyReadingReminderTime', '08:00'));
        if (preg_match('/^(\d{1,2}):(\d{2})$/', $time, $matches) !== 1) {
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
