<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Notifications;

use PHPUnit\Framework\TestCase;

final class ReadingReceiptContractTest extends TestCase
{
    public function testPublicReadingTriggersTheReceiptAfterSaving(): void
    {
        $controller = file_get_contents(APPPATH . 'Controllers/PublicEquipmentReadings.php');

        self::assertIsString($controller);
        self::assertStringContainsString('sendReadingReceipt', $controller);
        self::assertStringContainsString("'reading_receipt'", $controller);
        self::assertStringContainsString('ReadingReceiptMessageBuilder::receiptCode', $controller);
        self::assertStringContainsString('best-effort', $controller);
    }

    public function testTheSuccessScreenShowsTheSavedValueAndReceipt(): void
    {
        $view = file_get_contents(APPPATH . 'Views/public_equipment/reading.php');

        self::assertIsString($view);
        self::assertStringContainsString('readingReceipt', $view);
        self::assertStringContainsString("receipt_code", $view);
        self::assertStringContainsString("receipt_km", $view);
    }

    public function testReceiptsArePrioritizedInTheWhatsAppQueue(): void
    {
        $queue = file_get_contents(APPPATH . 'Infrastructure/Notifications/CodeIgniterWhatsAppNotificationDeliveryQueue.php');

        self::assertIsString($queue);
        self::assertStringContainsString("tipo_evento = 'equipo.lectura_registrada'\", 'DESC'", $queue);
    }

    public function testTheNotifierUsesTheMeasurementPort(): void
    {
        $port = file_get_contents(APPPATH . 'Application/Measurement/Port/ReadingReceiptNotifier.php');
        $adapter = file_get_contents(APPPATH . 'Infrastructure/Notifications/CodeIgniterReadingReceiptNotifier.php');

        self::assertIsString($port);
        self::assertIsString($adapter);
        self::assertStringContainsString('interface ReadingReceiptNotifier', $port);
        self::assertStringContainsString('implements ReadingReceiptNotifier', $adapter);
        self::assertStringContainsString('equipo.lectura_registrada', $adapter);
    }
}
