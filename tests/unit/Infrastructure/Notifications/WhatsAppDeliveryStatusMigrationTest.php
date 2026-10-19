<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class WhatsAppDeliveryStatusMigrationTest extends TestCase
{
    public function testEstadoFitsPendingConfirmationAndRepairsTruncatedRows(): void
    {
        $source = file_get_contents(APPPATH . 'Database/Migrations/2026-10-19-090000_WidenWhatsAppDeliveryStatus.php');

        self::assertIsString($source);
        self::assertStringContainsString("'constraint' => 30", $source);
        self::assertStringContainsString("'PENDIENTE_CONFIRMACI'", $source);
        self::assertStringContainsString("'PENDIENTE_CONFIRMACION'", $source);
        self::assertStringContainsString('tableExists', $source);
        self::assertStringContainsString('modifyColumn', $source);
    }
}
