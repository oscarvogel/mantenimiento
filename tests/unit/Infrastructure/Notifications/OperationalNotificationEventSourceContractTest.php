<?php

declare(strict_types=1);

use App\Domain\WorkOrders\WorkOrderStatus;
use PHPUnit\Framework\TestCase;

final class OperationalNotificationEventSourceContractTest extends TestCase
{
    public function testCurrentOperationalEventsAndIdempotencyKeysRemainExplicit(): void
    {
        $source = file_get_contents(APPPATH . 'Infrastructure/Notifications/CodeIgniterOperationalNotificationEventSource.php');
        self::assertIsString($source);

        foreach ([
            'preventivo.proximo',
            'preventivo.vencido',
            'equipo.sin_lectura',
            'orden.asignada',
            'orden.proxima_objetivo',
            'orden.demorada',
            'orden.espera_repuestos',
        ] as $eventType) {
            self::assertStringContainsString("'{$eventType}'", $source);
        }

        self::assertStringContainsString('orden_asignada:ot:', $source);
        self::assertStringContainsString('orden_proxima_objetivo:ot:', $source);
        self::assertStringContainsString('orden_demorada:ot:', $source);
        self::assertStringContainsString('orden_espera_repuestos:ot:', $source);
        self::assertStringContainsString('equipo_sin_lectura:equipo:', $source);
        self::assertStringContainsString('ciclo:{$cycle}', $source);
    }

    public function testWaitingNotificationUsesTheDomainWorkOrderStatus(): void
    {
        self::assertSame('EN_ESPERA_REPUESTOS', WorkOrderStatus::WAITING_FOR_PARTS->value);

        $source = file_get_contents(APPPATH . 'Infrastructure/Notifications/CodeIgniterOperationalNotificationEventSource.php');
        self::assertIsString($source);
        self::assertStringContainsString('WorkOrderStatus::WAITING_FOR_PARTS->value', $source);
    }

    public function testDueSoonAndDelayedRulesDoNotOverlapForFutureObjectives(): void
    {
        $source = file_get_contents(APPPATH . 'Infrastructure/Notifications/CodeIgniterOperationalNotificationEventSource.php');
        self::assertIsString($source);

        self::assertStringContainsString('$objective >= $now && $objective <= $dueSoonUntil', $source);
        self::assertStringContainsString('$objective !== null ? $objective < $now', $source);
    }

    public function testBlockedEventsAreDocumentedInsteadOfGuessed(): void
    {
        $matrix = file_get_contents(ROOTPATH . 'docs/notificaciones-eventos-operativos.md');
        self::assertIsString($matrix);

        self::assertStringContainsString('orden.reasignada', $matrix);
        self::assertStringContainsString('solicitud.critica', $matrix);
        self::assertStringContainsString('garantia.proxima', $matrix);
        self::assertStringContainsString('No se marcan como implementados', $matrix);
    }
}
