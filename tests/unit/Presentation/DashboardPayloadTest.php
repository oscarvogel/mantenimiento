<?php

declare(strict_types=1);

use App\Application\Identity\ActorContext;
use App\Presentation\DashboardPayload;
use PHPUnit\Framework\TestCase;

final class DashboardPayloadTest extends TestCase
{
    public function testBuildsScopedDeepLinksForMetricsAndMaintenanceRows(): void
    {
        $actor = new ActorContext(7, 5, false, true, ['Administrador'], ['equipos.ver', 'planes.ver'], []);
        $payload = (new DashboardPayload())->fromOperations($actor, [
            'metrics' => ['maintenanceOverdue' => 1],
            'upcomingMaintenance' => [[
                'planId' => 12, 'equipmentId' => 14, 'equipmentCode' => 'CAM-14',
                'status' => 'VENCIDO', 'statusLabel' => 'Vencido',
            ]],
        ]);

        self::assertStringContainsString('/mantenimiento/planes?estado=VENCIDO', $payload['links']['maintenanceOverdue']);
        self::assertStringContainsString('equipo_id=14', $payload['upcomingMaintenance'][0]['actionUrl']);
        self::assertSame('Atender', $payload['upcomingMaintenance'][0]['actionLabel']);
        self::assertStringContainsString('/mantenimiento/equipos/14', $payload['upcomingMaintenance'][0]['detailUrl']);
    }

    public function testHidesActionsWithoutPermissions(): void
    {
        $actor = new ActorContext(7, 5, false, true, ['Consulta'], [], []);
        $payload = (new DashboardPayload())->fromOperations($actor, [
            'metrics' => [],
            'upcomingMaintenance' => [['equipmentId' => 14, 'status' => 'PROXIMO']],
        ]);

        self::assertSame('#', $payload['links']['maintenanceOverdue']);
        self::assertNull($payload['upcomingMaintenance'][0]['actionUrl']);
        self::assertNull($payload['upcomingMaintenance'][0]['actionLabel']);
    }
}
