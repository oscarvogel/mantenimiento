<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ExpirationNotificationContractTest extends TestCase
{
    public function testExpirationEventsCoverEquipmentAndEmployeesWithTenantScopedJoins(): void
    {
        $source = file_get_contents(APPPATH . 'Infrastructure/Notifications/CodeIgniterOperationalNotificationEventSource.php');
        self::assertIsString($source);

        foreach ([
            'equipo.vencimiento_proximo',
            'equipo.vencimiento_vencido',
            'empleado.vencimiento_proximo',
            'empleado.vencimiento_vencido',
        ] as $eventType) {
            self::assertStringContainsString("'{$eventType}'", $source);
        }

        self::assertStringContainsString('t.id = v.tipo_vencimiento_id AND t.empresa_id = v.empresa_id', $source);
        self::assertStringContainsString('e.id = v.equipo_id AND e.empresa_id = v.empresa_id', $source);
        self::assertStringContainsString('emp.id = v.empleado_id AND emp.empresa_id = v.empresa_id', $source);
        self::assertStringContainsString('vencimiento:{$row[\'id\']}:fecha:', $source);
    }

    public function testExpirationSourceIsBackwardCompatibleBeforeMigrationRuns(): void
    {
        $source = file_get_contents(APPPATH . 'Infrastructure/Notifications/CodeIgniterOperationalNotificationEventSource.php');
        self::assertIsString($source);

        self::assertStringContainsString("tableExists('vencimientos')", $source);
        self::assertStringContainsString("tableExists('tipos_vencimiento')", $source);
        self::assertStringContainsString('return [];', $source);
    }
}
