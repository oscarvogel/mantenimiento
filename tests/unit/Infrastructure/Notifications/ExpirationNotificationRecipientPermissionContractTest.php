<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ExpirationNotificationRecipientPermissionContractTest extends TestCase
{
    public function testEmployeeAndEquipmentExpirationEventsUseTheirOwnViewPermissions(): void
    {
        $source = file_get_contents(APPPATH . 'Infrastructure/Notifications/CodeIgniterNotificationRecipientResolver.php');
        self::assertIsString($source);

        self::assertStringContainsString(
            "str_starts_with($type, 'empleado.vencimiento_') => 'empleados.ver'",
            $source,
        );
        self::assertStringContainsString(
            "str_starts_with($type, 'equipo.vencimiento_') => 'equipos.ver'",
            $source,
        );
    }
}
