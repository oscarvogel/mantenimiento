<?php

declare(strict_types=1);

namespace App\Commands;

use App\Application\Notifications\RunNotificationCycle;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

final class DispatchNotifications extends BaseCommand
{
    protected $group = 'Mantenimiento';
    protected $name = 'notifications:dispatch';
    protected $description = 'Envía resúmenes por email y Web Push pendientes con bloqueo e idempotencia.';

    public function run(array $params): void
    {
        $cycle = new RunNotificationCycle(
            service('detectOverduePlansAutomatically'),
            service('operationalNotificationCollector'),
            service('notificationDispatch'),
            service('notificationClock'),
        );
        $result = $cycle->execute($params[0] ?? null, (int) env('alerts.lockTimeoutSeconds', 900));

        CLI::write(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'green');
    }
}
