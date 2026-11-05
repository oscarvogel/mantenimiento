<?php

declare(strict_types=1);

namespace App\Application\Notifications;

use App\Application\Notifications\Port\AutomationExecutionRecorder;
use App\Application\Notifications\Port\NotificationClock;
use App\Application\Notifications\Port\NotificationProcessControl;
use App\Application\Telematic\RefreshTelemetryNow;
use App\Application\PreventiveMaintenance\DetectOverduePlansAutomatically;
use Throwable;

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
        private ?NotificationProcessControl $processes = null,
        private ?AutomationExecutionRecorder $recorder = null,
    ) {
    }

    /** @return array{execution_key:string,status:string,stages:list<array<string,mixed>>,overdue:mixed,telemetry:array{companies:int,integration_failures:int},collected:array{events:int,created:int,duplicates:int},dispatched:array<string,int>} */
    public function execute(?string $executionKey = null, int $lockTtl = 900, int $dispatchLimit = 250): array
    {
        $key = trim((string) $executionKey);
        if ($key === '') {
            // El cron productivo puede correr cada cinco minutos para mantener
            // la telemetría. La clave por minuto permite cada ejecución válida
            // dentro de la misma hora.
            $key = $this->clock->now()->format('Y-m-d-H-i');
        }

        // El dueño de la exclusión del ciclo completo es este caso de uso. El
        // despacho directo conserva su propio lock más abajo y no se anida con
        // este: son procesos distintos.
        $cycleToken = null;
        if ($this->processes !== null) {
            $cycleToken = $this->processes->acquire('notificaciones:ciclo', $lockTtl);
            if ($cycleToken === null) {
                throw new NotificationDispatchInProgress('Ya existe un ciclo de notificaciones activo.');
            }
        }

        $startedAt = microtime(true);
        $cycleExecutionId = null;
        $stages = [];
        log_message('notice', 'Inicio ciclo global de notificaciones. key={key} limit={limit}', [
            'key' => $key,
            'limit' => max(1, $dispatchLimit),
        ]);

        try {
            if ($this->processes !== null) {
                $cycleExecutionId = $this->processes->start('notificaciones:ciclo', $key);
                if ($cycleExecutionId === null) {
                    return $this->alreadyCompletedResult($key);
                }
            }

            $overdue = $this->runStage(
                'notificaciones:ciclo:vencimientos',
                $key,
                null,
                $stages,
                fn (): mixed => $this->detectOverdue->execute(),
                [],
            );

            $telemetrySummary = ['companies' => 0, 'integration_failures' => 0];

            $collected = $this->runStage(
                'notificaciones:ciclo:recoleccion',
                $key,
                null,
                $stages,
                fn (): array => $this->collector->execute(),
                ['events' => 0, 'created' => 0, 'duplicates' => 0],
            );

            $missingDriverPhones = $this->missingDriverPhones === null
                ? $this->skipStage('notificaciones:ciclo:telefonos_choferes', $key, null, 'La etapa no está configurada.', $stages)
                : $this->runStage(
                    'notificaciones:ciclo:telefonos_choferes',
                    $key,
                    null,
                    $stages,
                    fn (): mixed => $this->missingDriverPhones?->execute(),
                    null,
                );

            $managementReports = $this->managementReports === null
                ? $this->skipStage('notificaciones:ciclo:informes_gerenciales', $key, null, 'La etapa no está configurada.', $stages)
                : $this->runStage(
                    'notificaciones:ciclo:informes_gerenciales',
                    $key,
                    null,
                    $stages,
                    fn (): mixed => $this->managementReports?->execute(),
                    null,
                );

            $managementReadingReport = $this->managementReadingReport === null
                ? $this->skipStage('notificaciones:ciclo:informe_lecturas', $key, null, 'La etapa no está configurada.', $stages)
                : $this->runStage(
                    'notificaciones:ciclo:informe_lecturas',
                    $key,
                    null,
                    $stages,
                    fn (): mixed => $this->managementReadingReport?->execute(),
                    null,
                );

            $dispatched = $this->runStage(
                'notificaciones:ciclo:despacho',
                $key,
                null,
                $stages,
                fn (): array => $this->dispatch->execute($key, $lockTtl, max(1, $dispatchLimit)),
                [],
                NotificationDispatchInProgress::class,
            );

            // La telemetría corre al final a propósito. Es la etapa más pesada y en
            // Ferozo puede agotar el límite de ejecución del hosting; si corriera
            // antes, un backlog de telemetría deja sin despachar los recordatorios
            // de km. Los eventos de telemetría se publican y salen en el próximo
            // ciclo de cinco minutos.
            $telemetryInterval = max(5, (int) env('alerts.telemetryRefreshMinutes', 15));
            $runTelemetry = ((int) $this->clock->now()->format('i')) % $telemetryInterval === 0;
            if (! $runTelemetry) {
                $this->skipStage(
                    'notificaciones:ciclo:telemetria',
                    $key,
                    null,
                    'No corresponde ejecutar telemetría en este minuto.',
                    $stages,
                );
                log_message('notice', 'Etapa telemetría omitida: corre cada {minutes} minutos.', [
                    'minutes' => $telemetryInterval,
                ]);
            } elseif ($this->telemetryRefreshes === []) {
                $this->skipStage(
                    'notificaciones:ciclo:telemetria',
                    $key,
                    null,
                    'No hay integraciones de telemetría configuradas.',
                    $stages,
                );
            } else {
                $telemetryStartedAt = microtime(true);
                $telemetryId = $this->beginStage('notificaciones:ciclo:telemetria', $key, null);
                $telemetryFailed = false;
                foreach ($this->telemetryRefreshes as $refresh) {
                    $telemetrySummary['companies']++;
                    try {
                        $result = $refresh->execute();
                        $telemetrySummary['integration_failures'] += $result->fallos();
                    } catch (Throwable $exception) {
                        $telemetrySummary['integration_failures']++;
                        $telemetryFailed = true;
                        log_message('error', 'Falló la actualización programada de telemetría ({type}).', [
                            'type' => $exception::class,
                        ]);
                    }
                }
                if ($telemetrySummary['integration_failures'] > 0) {
                    $telemetryFailed = true;
                }
                if ($telemetryFailed) {
                    $this->failStage($telemetryId, 'Fallos de integración de telemetría: ' . $telemetrySummary['integration_failures'] . '.');
                    $stages[] = ['process' => 'notificaciones:ciclo:telemetria', 'state' => 'FALLIDA'];
                } else {
                    $this->finishStage($telemetryId, $telemetrySummary);
                    $stages[] = ['process' => 'notificaciones:ciclo:telemetria', 'state' => 'COMPLETADA'];
                }
                log_message('notice', 'Etapa telemetría completada en {seconds}s. empresas={companies} fallos_integracion={failures}', [
                    'seconds' => number_format(microtime(true) - $telemetryStartedAt, 3, '.', ''),
                    'companies' => $telemetrySummary['companies'],
                    'failures' => $telemetrySummary['integration_failures'],
                ]);
            }

            $failedStages = count(array_filter($stages, static fn (array $stage): bool => $stage['state'] === 'FALLIDA'));
            $status = $failedStages > 0 ? 'parcial' : 'completada';
            if ($cycleExecutionId !== null) {
                $summary = [
                    'stages' => count($stages),
                    'completed' => count(array_filter($stages, static fn (array $stage): bool => $stage['state'] === 'COMPLETADA')),
                    'failed' => $failedStages,
                    'skipped' => count(array_filter($stages, static fn (array $stage): bool => $stage['state'] === 'OMITIDA')),
                ];
                if ($failedStages > 0) {
                    $this->processes?->fail($cycleExecutionId, 'Ciclo parcial: una o más etapas fallaron. Revisar automatizacion_etapas.');
                } else {
                    $this->processes?->finish($cycleExecutionId, $summary);
                }
            }

            log_message('notice', 'Fin ciclo global de notificaciones en {seconds}s. key={key} status={status}', [
                'seconds' => number_format(microtime(true) - $startedAt, 3, '.', ''),
                'key' => $key,
                'status' => $status,
            ]);

            return [
                'execution_key' => $key,
                'status' => $status,
                'stages' => $stages,
                'overdue' => $overdue,
                'telemetry' => $telemetrySummary,
                'collected' => $collected,
                'missing_driver_phones' => $missingDriverPhones,
                'management_reports' => $managementReports,
                'management_reading_report' => $managementReadingReport,
                'dispatched' => $dispatched,
            ];
        } catch (Throwable $exception) {
            if ($cycleExecutionId !== null) {
                $this->processes?->fail($cycleExecutionId, 'Ciclo interrumpido: ' . $exception::class);
            }
            throw $exception;
        } finally {
            if ($cycleToken !== null) {
                $this->processes?->release('notificaciones:ciclo', $cycleToken);
            }
        }
    }

    /**
     * @param list<array<string,mixed>> $stages
     *
     * @return mixed
     */
    private function runStage(
        string $process,
        string $executionKey,
        ?int $companyId,
        array &$stages,
        callable $operation,
        mixed $default,
        ?string $rethrow = null,
    ): mixed {
        $id = $this->beginStage($process, $executionKey, $companyId);
        try {
            $result = $operation();
            $reportedFailures = is_array($result) && isset($result['failed']) ? (int) $result['failed'] : 0;
            if ($reportedFailures > 0) {
                $this->failStage($id, 'La etapa reportó ' . $reportedFailures . ' fallo(s).');
                $stages[] = ['process' => $process, 'state' => 'FALLIDA'];
            } else {
                $this->finishStage($id, is_array($result) ? $result : ['result' => $result]);
                $stages[] = ['process' => $process, 'state' => 'COMPLETADA'];
            }

            return $result;
        } catch (Throwable $exception) {
            $this->failStage($id, 'La etapa falló (' . $exception::class . ').');
            $stages[] = ['process' => $process, 'state' => 'FALLIDA'];
            log_message('error', 'Falló la etapa {stage} del ciclo de notificaciones ({type}).', [
                'stage' => $process,
                'type' => $exception::class,
            ]);

            if ($rethrow !== null && $exception instanceof $rethrow) {
                throw $exception;
            }

            return $default;
        }
    }

    /** @param list<array<string,mixed>> $stages */
    private function skipStage(string $process, string $executionKey, ?int $companyId, string $reason, array &$stages): null
    {
        $id = $this->beginStage($process, $executionKey, $companyId);
        if ($id !== null) {
            try {
                $this->recorder?->skip($id, $reason);
            } catch (Throwable $exception) {
                log_message('warning', 'No se pudo registrar la etapa omitida {stage}: {type}.', [
                    'stage' => $process,
                    'type' => $exception::class,
                ]);
            }
        }
        $stages[] = ['process' => $process, 'state' => 'OMITIDA', 'reason' => $reason];

        return null;
    }

    private function beginStage(string $process, string $executionKey, ?int $companyId): ?int
    {
        if ($this->recorder === null) {
            return null;
        }

        try {
            return $this->recorder->begin($process, $executionKey, $companyId);
        } catch (Throwable $exception) {
            log_message('warning', 'No se pudo abrir la etapa {stage}: {type}.', [
                'stage' => $process,
                'type' => $exception::class,
            ]);

            return null;
        }
    }

    /** @param array<string,mixed> $summary */
    private function finishStage(?int $id, array $summary): void
    {
        if ($id === null) {
            return;
        }

        try {
            $safe = [];
            foreach ($summary as $key => $value) {
                if (is_scalar($value) || $value === null) {
                    $safe[(string) $key] = $value;
                }
            }
            $this->recorder?->finish($id, $safe);
        } catch (Throwable $exception) {
            log_message('warning', 'No se pudo cerrar la etapa {id}: {type}.', [
                'id' => $id,
                'type' => $exception::class,
            ]);
        }
    }

    private function failStage(?int $id, string $safeError): void
    {
        if ($id === null) {
            return;
        }

        try {
            $this->recorder?->fail($id, $safeError);
        } catch (Throwable $exception) {
            log_message('warning', 'No se pudo marcar como fallida la etapa {id}: {type}.', [
                'id' => $id,
                'type' => $exception::class,
            ]);
        }
    }

    /** @return array<string,mixed> */
    private function alreadyCompletedResult(string $key): array
    {
        return [
            'execution_key' => $key,
            'status' => 'completada',
            'stages' => [],
            'overdue' => [],
            'telemetry' => ['companies' => 0, 'integration_failures' => 0],
            'collected' => ['events' => 0, 'created' => 0, 'duplicates' => 0],
            'missing_driver_phones' => null,
            'management_reports' => null,
            'management_reading_report' => null,
            'dispatched' => ['email_sent' => 0, 'company_email_sent' => 0, 'push_sent' => 0, 'whatsapp_sent' => 0, 'failed' => 0, 'retry' => 0, 'expired' => 0, 'skipped' => 0, 'already_completed' => 1],
        ];
    }
}
