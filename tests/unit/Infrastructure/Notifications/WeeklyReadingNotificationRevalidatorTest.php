<?php

declare(strict_types=1);

use App\Infrastructure\Notifications\WeeklyReadingNotificationRevalidator;
use PHPUnit\Framework\TestCase;

final class WeeklyReadingNotificationRevalidatorTest extends TestCase
{
    public function testExtractsIsoWeekOfPendingNotification(): void
    {
        $key = 'lectura.semanal.incumplida:empresa:1:equipo:42:chofer:9:semana:2026-W40:usuario:3';
        $monday = WeeklyReadingNotificationRevalidator::weekStartFromEventKey($key);
        self::assertNotNull($monday);
        self::assertSame('2026-09-28 00:00:00', $monday->format('Y-m-d H:i:s'));
    }

    public function testSupportsWeekAcrossCalendarYear(): void
    {
        $monday = WeeklyReadingNotificationRevalidator::weekStartFromEventKey('semana:2026-W01');
        self::assertNotNull($monday);
        self::assertSame('2025-12-29', $monday->format('Y-m-d'));
    }

    public function testRejectsMalformedAndNonexistentWeeks(): void
    {
        foreach (['x', 'semana:2026-W00', 'semana:2026-W54', 'semana:2026-W53', 'semana:2026-W40-extra'] as $key) {
            self::assertNull(WeeklyReadingNotificationRevalidator::weekStartFromEventKey($key), $key);
        }
    }

    public function testDigestRevalidatesBeforeSelectingPendingRows(): void
    {
        $queue = file_get_contents(APPPATH . 'Infrastructure/Notifications/CodeIgniterWhatsAppNotificationDeliveryQueue.php');
        $service = file_get_contents(APPPATH . 'Infrastructure/Notifications/WeeklyReadingNotificationRevalidator.php');
        self::assertIsString($queue);
        self::assertIsString($service);
        $method = substr($queue, (int) strpos($queue, 'private function hydrateUserDailyDigest('));
        self::assertLessThan(
            strpos($method, "$count = $this->db->table('notificaciones')"),
            strpos($method, '->revalidate($companyId, $userId, $this->clock->now())')
        );
        self::assertStringContainsString("->where('empresa_id', $companyId)", $service);
        self::assertStringContainsString("->where('equipo_id', $equipmentId)", $service);
        self::assertStringContainsString("->where('anulada', 0)", $service);
        self::assertStringContainsString("->where('kilometraje IS NOT NULL', null, false)", $service);
        self::assertStringContainsString("->where('fecha_lectura >=',", $service);
        self::assertStringContainsString("->where('estado', 'PENDIENTE')", $service);
        self::assertStringContainsString("'estado' => 'LEIDA'", $service);
    }
}
