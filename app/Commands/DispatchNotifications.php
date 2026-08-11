<?php

declare(strict_types=1);

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

final class DispatchNotifications extends BaseCommand
{
    protected $group = 'Mantenimiento';
    protected $name = 'notifications:dispatch';
    protected $description = 'Envía resúmenes por email y Web Push pendientes con bloqueo e idempotencia.';

    public function run(array $params): void
    {
        $key = $params[0] ?? service('notificationClock')->now()->format('Y-m-d-H');
        $collected = service('operationalNotificationCollector')->execute();
        $dispatched = service('notificationDispatch')->execute((string) $key, (int) env('alerts.lockTimeoutSeconds', 900));
        CLI::write(json_encode(['collected' => $collected, 'dispatched' => $dispatched], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'green');
    }
}
