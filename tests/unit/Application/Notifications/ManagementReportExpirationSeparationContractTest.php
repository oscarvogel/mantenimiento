<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Notifications;

use PHPUnit\Framework\TestCase;

final class ManagementReportExpirationSeparationContractTest extends TestCase
{
    public function testReportSeparatesDocumentExpirationsFromPreventiveMaintenance(): void
    {
        $source = file_get_contents(ROOTPATH . 'app/Application/Notifications/ScheduleManagementReports.php');

        self::assertIsString($source);
        self::assertStringContainsString('Documentación vencida:', $source);
        self::assertStringContainsString('Documentación próxima (30 días):', $source);
        self::assertStringContainsString('Preventivos vencidos:', $source);
        self::assertStringContainsString('Preventivos próximos:', $source);
        self::assertStringNotContainsString("'Vencimientos vencidos: '", $source);
        self::assertStringNotContainsString("'Vencimientos próximos (30 días): '", $source);
    }

    public function testPreventiveCountsUsePlansAndMaintenanceRulesInsteadOfDocumentExpirations(): void
    {
        $source = file_get_contents(ROOTPATH . 'app/Application/Notifications/ScheduleManagementReports.php');

        self::assertIsString($source);
        self::assertStringContainsString('private function preventiveDueCounts(', $source);
        self::assertStringContainsString("table('planes_mantenimiento')", $source);
        self::assertStringContainsString("table('equipo_plan_mantenimiento')", $source);
        self::assertStringContainsString("'VENCIDO'", $source);
        self::assertStringContainsString("'PROXIMO'", $source);
    }
}
