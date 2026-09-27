<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class WhatsAppExpirationDigestContractTest extends TestCase
{
    public function testDigestIsDailyPerCompanyAndDriver(): void
    {
        $source = file_get_contents(APPPATH . 'Infrastructure/Notifications/CodeIgniterWhatsAppNotificationDeliveryQueue.php');
        self::assertIsString($source);

        self::assertStringContainsString('resumen_vencimientos:empresa:', $source);
        self::assertStringContainsString("'tipo_evento' => 'equipo.resumen_vencimientos'", $source);
        self::assertStringContainsString("where('clave_entrega'", $source);
    }

    public function testDigestUsesAgreedMilestonesAndDailyOverdueRule(): void
    {
        $source = file_get_contents(APPPATH . 'Infrastructure/Notifications/CodeIgniterWhatsAppNotificationDeliveryQueue.php');
        self::assertIsString($source);

        self::assertStringContainsString('15, 7, 0', $source);
        self::assertStringContainsString('in_array', $source);
    }

    public function testPendingRegularizationSuspendsExpirationReminder(): void
    {
        $source = file_get_contents(APPPATH . 'Infrastructure/Notifications/CodeIgniterWhatsAppNotificationDeliveryQueue.php');
        self::assertIsString($source);

        self::assertStringContainsString("tableExists('vencimiento_regularizaciones')", $source);
        self::assertStringContainsString("vr.estado = 'PENDIENTE'", $source);
        self::assertStringContainsString('vr.vencimiento_id = v.id', $source);
    }

    public function testCompanyBrandIsPrimaryAndVogelIsSecondary(): void
    {
        $source = file_get_contents(APPPATH . 'Infrastructure/Notifications/CodeIgniterWhatsAppNotificationDeliveryQueue.php');
        self::assertIsString($source);

        self::assertStringContainsString(' · Mantenimiento*', $source);
        self::assertStringContainsString('_Sistema de mantenimiento desarrollado por Vogel Consultoría._', $source);
    }
}
