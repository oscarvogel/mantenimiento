<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class NotificationPreferenceScopeContractTest extends TestCase
{
    public function testMultiRolePreferencesAreCombinedInsteadOfTakingFirstInsertedRole(): void
    {
        $source = file_get_contents(APPPATH . 'Infrastructure/Notifications/CodeIgniterNotificationPreferenceStore.php');
        self::assertIsString($source);
        self::assertStringContainsString('combineRolePreferences', $source);
        self::assertStringContainsString('mostPermissive', $source);
        self::assertStringNotContainsString("->orderBy('p.id')->get()->getRowArray()", $source);
    }

    public function testRecipientResolverEnforcesTenantBranchActivityAndNotificationPermission(): void
    {
        $source = file_get_contents(APPPATH . 'Infrastructure/Notifications/CodeIgniterNotificationRecipientResolver.php');
        self::assertIsString($source);
        self::assertStringContainsString("->where('u.empresa_id', \$event->companyId())", $source);
        self::assertStringContainsString("->where('u.activo', 1)", $source);
        self::assertStringContainsString("p_n.clave = 'notificaciones.ver'", $source);
        self::assertStringContainsString('recipientUserIds()', $source);
        self::assertStringContainsString('usuario_sucursales', $source);
        self::assertStringContainsString('$this->scope->allows(', $source);
    }

    public function testNewOperationalEventsReceiveRoleDefaultsOnExistingInstallations(): void
    {
        $migration = file_get_contents(APPPATH . 'Database/Migrations/2026-08-25-230500_BackfillNotificationRoleDefaults.php');
        $seeder = file_get_contents(APPPATH . 'Database/Seeds/NotificationDefaultsSeeder.php');
        self::assertIsString($migration);
        self::assertIsString($seeder);
        foreach (['orden.proxima_objetivo', 'orden.espera_repuestos'] as $event) {
            self::assertStringContainsString($event, $migration);
            self::assertStringContainsString($event, $seeder);
        }
    }
}
