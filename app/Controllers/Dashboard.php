<?php

namespace App\Controllers;

use App\Application\Dashboard\GetMaintenanceDashboard;
use App\Infrastructure\Identity\SessionActorContext;
use App\Presentation\AppShellPayload;
use CodeIgniter\Controller;

class Dashboard extends Controller
{
    public function index()
    {
        $actor = (new SessionActorContext())->current();

        if ($actor === null) {
            return redirect()->to('/login');
        }

        $canSeeOperations = ! $actor->isSuperAdmin() && array_filter(
            ['equipos.ver', 'planes.ver', 'ordenes.ver', 'ordenes.mi_trabajo'],
            static fn (string $permission): bool => $actor->hasPermission($permission),
        ) !== [];
        $operations = $canSeeOperations
            ? $this->maintenanceDashboard()->execute($actor)
            : $this->emptyOperations();

        $dashboardPayload = $this->appShell()->for($actor, 'dashboard') + [
            'page' => 'dashboard',
            'metrics' => $operations['metrics'],
            'upcomingMaintenance' => $operations['upcomingMaintenance'],
        ];

        return view('app', [
            'appPayload' => $dashboardPayload,
            'pageTitle' => 'Panel de mantenimiento',
        ]);
    }

    private function maintenanceDashboard(): GetMaintenanceDashboard
    {
        return service('maintenanceDashboard');
    }

    private function appShell(): AppShellPayload
    {
        return service('appShellPayload');
    }

    /** @return array<string,mixed> */
    private function emptyOperations(): array
    {
        return [
            'metrics' => [
                'equipmentTotal' => 0,
                'equipmentActive' => 0,
                'maintenanceDueSoon' => 0,
                'maintenanceOverdue' => 0,
                'maintenanceScheduled' => 0,
                'openOrders' => 0,
            ],
            'upcomingMaintenance' => [],
        ];
    }
}
