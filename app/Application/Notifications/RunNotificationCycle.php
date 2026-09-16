<?php

declare(strict_types=1);

namespace App\Application\Notifications;

use App\Application\Notifications\Port\NotificationClock;
use App\Application\PreventiveMaintenance\DetectOverduePlansAutomatically;

final readonly class RunNotificationCycle
{
    public function __construct(
        private DetectOverduePlansAutomatically $detectOverdue,
        private CollectOperationalNotifications $collector,
        private RunNotificationDispatch $dispatch,
        private NotificationClock $clock,
        private ?ScheduleManagementReports $managementReports = null,
    ) {
    }

    /** @return array{execution_key:string,overdue:mixed,collected:array{events:int,created:int,duplicates:int},dispatched:array<string,int>} */
    public function execute(?string $executionKey = null, int $lockTtl = 900, int $dispatchLimit = 250): array
    {
        $key = trim((string) $executionKey);
        if ($key === '') {
            // El cron productivo puede correr más de una vez por hora (por ejemplo,
            // cada 30 minutos). La clave por minuto evita que una segunda ejecución
            // válida dentro de la misma hora quede marcada como ya completada.
            $key = $this->clock->now()->format('Y-m-d-H-i');
        }

        $startedAt = microtime(true);
        log_message('notice', 'Inicio ciclo global de notificaciones. key={key} limit={limit}', [
            'key' => $key,
            'limit' => max(1, $dispatchLimit),
        ]);

        $overdueStartedAt = microtime(true);
        $overdue = $this->detectOverdue->execute();
        log_message('notice', 'Etapa vencimientos completada en {seconds}s.', [
            'seconds' => number_format(microtime(true) - $overdueStartedAt, 3, '.', ''),
        ]);

        $collectStartedAt = microtime(true);
        $collected = $this->collector->execute();
        log_message('notice', 'Etapa recolección completada en {seconds}s.', [
            'seconds' => number_format(microtime(true) - $collectStartedAt, 3, '.', ''),
        ]);

        $reportsStartedAt = microtime(true);
        $managementReports = $this->managementReports?->execute();
        log_message('notice', 'Etapa informes gerenciales completada en {seconds}s.', [
            'seconds' => number_format(microtime(true) - $reportsStartedAt, 3, '.', ''),
        ]);

        $dispatchStartedAt = microtime(true);
        $dispatched = $this->dispatch->execute($key, $lockTtl, max(1, $dispatchLimit));
        log_message('notice', 'Etapa despacho completada en {seconds}s.', [
            'seconds' => number_format(microtime(true) - $dispatchStartedAt, 3, '.', ''),
        ]);

        log_message('notice', 'Fin ciclo global de notificaciones en {seconds}s. key={key}', [
            'seconds' => number_format(microtime(true) - $startedAt, 3, '.', ''),
            'key' => $key,
        ]);

        return [
            'execution_key' => $key,
            'overdue' => $overdue,
            'collected' => $collected,
            'management_reports' => $managementReports,
            'dispatched' => $dispatched,
        ];
    }
}
