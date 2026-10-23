<?php

declare(strict_types=1);

namespace App\Application\Notifications;

use App\Application\Notifications\Port\NotificationClock;
use App\Application\Telematic\RefreshTelemetryNow;
use App\Application\PreventiveMaintenance\DetectOverduePlansAutomatically;

final readonly class RunNotificationCycle
{
    public function __construct(
        private DetectOverduePlansAutomatically $detectOverdue,
        private CollectOperationalNotifications $collector,
        private RunNotificationDispatch $dispatch,
        private NotificationClock $clock,
        private ?ScheduleManagementReports $managementReports = null,
        private ?ScheduleManagementReadingReport $managementReadingReport = null,
        private ?NotifyAdminsMissingDriverPhones $missingDriverPhones = null,
        /** @var list<RefreshTelemetryNow> */
        private array $telemetryRefreshes = [],
    ) {
    }

    /** @return array{execution_key:string,overdue:mixed,telemetry:array{companies:int,integration_failures:int},collected:array{events:int,created:int,duplicates:int},dispatched:array<string,int>} */
    public function execute(?string $executionKey = null, int $lockTtl = 900, int $dispatchLimit = 250): array
    {
        $key = trim((string) $executionKey);
        if ($key === '') {
            // El cron productivo puede correr cada cinco minutos para mantener
            // la telemetría. La clave por minuto permite cada ejecución válida
            // dentro de la misma hora.
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

        $telemetrySummary = ['companies' => 0, 'integration_failures' => 0];

        $collectStartedAt = microtime(true);
        $collected = $this->collector->execute();
        log_message('notice', 'Etapa recolección completada en {seconds}s.', [
            'seconds' => number_format(microtime(true) - $collectStartedAt, 3, '.', ''),
        ]);

        $driverPhonesStartedAt = microtime(true);
        $missingDriverPhones = $this->missingDriverPhones?->execute();
        log_message('notice', 'Etapa control teléfonos de choferes completada en {seconds}s.', [
            'seconds' => number_format(microtime(true) - $driverPhonesStartedAt, 3, '.', ''),
        ]);

        $reportsStartedAt = microtime(true);
        $managementReports = $this->managementReports?->execute();
        log_message('notice', 'Etapa informes gerenciales completada en {seconds}s.', [
            'seconds' => number_format(microtime(true) - $reportsStartedAt, 3, '.', ''),
        ]);

        $readingReportStartedAt = microtime(true);
        $managementReadingReport = $this->managementReadingReport?->execute();
        log_message('notice', 'Etapa informe de lecturas a gerencia completada en {seconds}s.', [
            'seconds' => number_format(microtime(true) - $readingReportStartedAt, 3, '.', ''),
        ]);

        $dispatchStartedAt = microtime(true);
        $dispatched = $this->dispatch->execute($key, $lockTtl, max(1, $dispatchLimit));
        log_message('notice', 'Etapa despacho completada en {seconds}s.', [
            'seconds' => number_format(microtime(true) - $dispatchStartedAt, 3, '.', ''),
        ]);

        // La telemetría corre al final a propósito. Es la etapa más pesada y en
        // Ferozo puede agotar el límite de ejecución del hosting; si corriera
        // antes, un backlog de telemetría deja sin despachar los recordatorios
        // de km. Los eventos de telemetría se publican y salen en el próximo
        // ciclo de cinco minutos.
        $telemetryInterval = max(5, (int) env('alerts.telemetryRefreshMinutes', 15));
        $runTelemetry = ((int) $this->clock->now()->format('i')) % $telemetryInterval === 0;
        if (! $runTelemetry) {
            log_message('notice', 'Etapa telemetría omitida: corre cada {minutes} minutos.', [
                'minutes' => $telemetryInterval,
            ]);
        } else {
            $telemetryStartedAt = microtime(true);
            foreach ($this->telemetryRefreshes as $refresh) {
                $telemetrySummary['companies']++;
                try {
                    $result = $refresh->execute();
                    $telemetrySummary['integration_failures'] += $result->fallos();
                } catch (\Throwable $exception) {
                    $telemetrySummary['integration_failures']++;
                    log_message('error', 'Falló la actualización programada de telemetría ({type}).', [
                        'type' => $exception::class,
                    ]);
                }
            }
            log_message('notice', 'Etapa telemetría completada en {seconds}s. empresas={companies} fallos_integracion={failures}', [
                'seconds' => number_format(microtime(true) - $telemetryStartedAt, 3, '.', ''),
                'companies' => $telemetrySummary['companies'],
                'failures' => $telemetrySummary['integration_failures'],
            ]);
        }

        log_message('notice', 'Fin ciclo global de notificaciones en {seconds}s. key={key}', [
            'seconds' => number_format(microtime(true) - $startedAt, 3, '.', ''),
            'key' => $key,
        ]);

        return [
            'execution_key' => $key,
            'overdue' => $overdue,
            'telemetry' => $telemetrySummary,
            'collected' => $collected,
            'missing_driver_phones' => $missingDriverPhones,
            'management_reports' => $managementReports,
            'management_reading_report' => $managementReadingReport,
            'dispatched' => $dispatched,
        ];
    }
}
