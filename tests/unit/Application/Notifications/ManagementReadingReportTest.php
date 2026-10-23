<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Notifications;

use App\Application\Notifications\ReadingComplianceReportMessageBuilder;
use App\Application\Notifications\ScheduleManagementReadingReport;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Tests\Support\Notifications\FakeGlobalNotificationSettingsStore;
use Tests\Support\Notifications\FakeManagementReadingReportSettings;
use Tests\Support\Notifications\FakeReadingComplianceReadModel;
use Tests\Support\Notifications\FakeWhatsAppDeliveryQueue;
use Tests\Support\Notifications\FixedNotificationClock;
use Tests\Support\Notifications\RecordingWhatsAppGateway;

/**
 * Informe automatico de equipos sin lectura para la duena / gerencia.
 *
 * El problema que resuelve: a los choferes se les reclama por WhatsApp
 * (automatico los lunes, miercoles y viernes, y a mano desde la pantalla) pero
 * no suben la lectura. El escalamiento correcto no es seguir reclamando al
 * chofer, es avisarle a quien puede ordenar: la gerencia.
 *
 * Estos tests atacan las reglas del circuito con dobles en los puertos, sin
 * base de datos y sin gateway real.
 */
final class ManagementReadingReportTest extends TestCase
{
    private const NOW = '2026-10-23 09:00:00';
    private const CONTROL_URL = 'https://vogelconsultoria.com.ar/mantenimiento/lecturas/control';

    // -------------------------------------------------------------------
    // 1-3. El aviso sale cuando hay equipos atrasados y dice cuáles
    // -------------------------------------------------------------------

    public function testReportIsQueuedForTheManagementContactWhenReadingsAreOverdue(): void
    {
        $queue = new FakeWhatsAppDeliveryQueue();
        $readings = $this->readings()->withItems([
            $this->item(11, 'AB499OK', 'AB499OK', 'ISMAEL HORACIO DUBE', '2026-10-12 09:58:00', 1002337),
            $this->item(9, 'AF117ZK', 'AF117ZK', 'MATLUK MARCELO', '2026-10-14 11:41:00', 492320),
        ]);

        $result = $this->useCase($this->settings(), $readings, $queue)->execute();

        self::assertSame(1, $result['queued']);
        self::assertCount(1, $queue->enqueued);
        self::assertSame('5493514449999', $queue->enqueued[0]['phone']);
        self::assertSame(5, $queue->enqueued[0]['companyId']);
    }

    public function testReportNamesTheEquipmentTheDaysAndTheLastKilometre(): void
    {
        $queue = new FakeWhatsAppDeliveryQueue();
        $readings = $this->readings()->withItems([
            $this->item(11, 'AB499OK', 'AB499OK', 'ISMAEL HORACIO DUBE', '2026-10-12 09:58:00', 1002337),
        ]);

        $this->useCase($this->settings(), $readings, $queue)->execute();

        $message = $queue->enqueued[0]['message'];
        self::assertStringContainsString('1 equipo sin lectura hace más de 7 días', $message);
        self::assertStringContainsString('AB499OK', $message);
        self::assertStringContainsString('hace 11 días', $message);
        self::assertStringContainsString('1.002.337 km', $message);
        self::assertStringContainsString('12/10/2026', $message);
        self::assertStringContainsString('ISMAEL HORACIO DUBE', $message);
    }

    public function testReportLinksToTheReadingControlScreen(): void
    {
        $queue = new FakeWhatsAppDeliveryQueue();
        $readings = $this->readings()->withItems([
            $this->item(11, 'AB499OK', 'AB499OK', 'ISMAEL HORACIO DUBE', '2026-10-12 09:58:00', 1002337),
        ]);

        $this->useCase($this->settings(), $readings, $queue)->execute();

        self::assertStringContainsString(self::CONTROL_URL . '?filter=gt_7', $queue->enqueued[0]['message']);
    }

    // -------------------------------------------------------------------
    // 4-6. No se molesta a la gerencia cuando no hay nada que informar
    // -------------------------------------------------------------------

    public function testNothingIsSentWhenEveryEquipmentReportedRecently(): void
    {
        $queue = new FakeWhatsAppDeliveryQueue();
        $readings = $this->readings()->withItems([]);

        $result = $this->useCase($this->settings(), $readings, $queue)->execute();

        self::assertSame(0, $result['queued']);
        self::assertSame([], $queue->enqueued);
    }

    public function testNothingIsSentWhenTheCompanyDidNotEnableTheReport(): void
    {
        $queue = new FakeWhatsAppDeliveryQueue();
        $readings = $this->readings()->withItems([
            $this->item(11, 'AB499OK', 'AB499OK', 'ISMAEL HORACIO DUBE', '2026-10-12 09:58:00', 1002337),
        ]);
        $settings = (new FakeManagementReadingReportSettings())->withCompany(5, ['enabled' => false]);

        $result = $this->useCase($settings, $readings, $queue)->execute();

        self::assertSame(0, $result['queued']);
        self::assertSame([], $queue->enqueued);
    }

    public function testNothingIsSentWhenTheManagementPhoneIsNotValid(): void
    {
        $queue = new FakeWhatsAppDeliveryQueue();
        $readings = $this->readings()->withItems([
            $this->item(11, 'AB499OK', 'AB499OK', 'ISMAEL HORACIO DUBE', '2026-10-12 09:58:00', 1002337),
        ]);
        $settings = (new FakeManagementReadingReportSettings())->withCompany(5, ['phone' => '351-444-9999']);

        $result = $this->useCase($settings, $readings, $queue)->execute();

        self::assertSame(0, $result['queued']);
        self::assertSame([], $queue->enqueued);
    }

    // -------------------------------------------------------------------
    // 7. Idempotencia: un aviso por empresa por dia
    // -------------------------------------------------------------------

    public function testOnlyOneReportPerCompanyAndDay(): void
    {
        $queue = new FakeWhatsAppDeliveryQueue();
        $readings = $this->readings()->withItems([
            $this->item(11, 'AB499OK', 'AB499OK', 'ISMAEL HORACIO DUBE', '2026-10-12 09:58:00', 1002337),
        ]);
        $useCase = $this->useCase($this->settings(), $readings, $queue);

        $first = $useCase->execute();
        $second = $useCase->execute();
        $third = $useCase->execute();

        self::assertSame(1, $first['queued']);
        self::assertSame(0, $second['queued']);
        self::assertSame(1, $second['duplicates']);
        self::assertSame(0, $third['queued']);
        self::assertCount(1, $queue->enqueued);
    }

    public function testTheReportComesBackTheNextDayWhileTheProblemLasts(): void
    {
        $queue = new FakeWhatsAppDeliveryQueue();
        $readings = $this->readings()->withItems([
            $this->item(11, 'AB499OK', 'AB499OK', 'ISMAEL HORACIO DUBE', '2026-10-12 09:58:00', 1002337),
        ]);
        $clock = $this->clock();
        $useCase = $this->useCase($this->settings(), $readings, $queue, $clock);

        self::assertSame(1, $useCase->execute()['queued']);

        $clock->advance('+1 day');
        self::assertSame(1, $useCase->execute()['queued'], 'El problema sigue: la gerencia lo ve todos los dias.');
        self::assertCount(2, $queue->enqueued);
        self::assertNotSame($queue->enqueued[0]['deliveryKey'], $queue->enqueued[1]['deliveryKey']);
    }

    // -------------------------------------------------------------------
    // 8. Modo piloto
    // -------------------------------------------------------------------

    public function testPilotModeSendsToThePilotPhoneAndNotToTheManagementContact(): void
    {
        $queue = new FakeWhatsAppDeliveryQueue();
        $readings = $this->readings()->withItems([
            $this->item(11, 'AB499OK', 'AB499OK', 'ISMAEL HORACIO DUBE', '2026-10-12 09:58:00', 1002337),
        ]);

        $result = $this->useCase($this->settings(), $readings, $queue, null, [
            'whatsapp_pilot_enabled' => true,
            'whatsapp_pilot_phone' => '5491133333333',
        ])->execute();

        self::assertSame(1, $result['queued']);
        self::assertSame('5491133333333', $queue->enqueued[0]['phone']);
        self::assertStringContainsString('PRUEBA CONTROLADA', $queue->enqueued[0]['message']);
    }

    // -------------------------------------------------------------------
    // 9. Ventana horaria
    // -------------------------------------------------------------------

    public function testNothingIsSentBeforeTheConfiguredHour(): void
    {
        $queue = new FakeWhatsAppDeliveryQueue();
        $readings = $this->readings()->withItems([
            $this->item(11, 'AB499OK', 'AB499OK', 'ISMAEL HORACIO DUBE', '2026-10-12 09:58:00', 1002337),
        ]);
        $clock = new FixedNotificationClock(new DateTimeImmutable('2026-10-23 07:30:00', new DateTimeZone('UTC')));

        $result = $this->useCase($this->settings(), $readings, $queue, $clock)->execute();

        self::assertSame(0, $result['queued']);
        self::assertSame([], $queue->enqueued);
    }

    // -------------------------------------------------------------------
    // 10. El umbral configurado por empresa viaja al corte
    // -------------------------------------------------------------------

    public function testTheCompanyThresholdDrivesTheCutoff(): void
    {
        $queue = new FakeWhatsAppDeliveryQueue();
        $readings = $this->readings()->withItems([
            $this->item(11, 'AB499OK', 'AB499OK', 'ISMAEL HORACIO DUBE', '2026-10-12 09:58:00', 1002337),
        ]);
        $settings = (new FakeManagementReadingReportSettings())->withCompany(5, ['thresholdDays' => 3]);

        $this->useCase($settings, $readings, $queue)->execute();

        self::assertSame('2026-10-20 00:00:00', $readings->calls[0]['cutoff']);
        self::assertSame(5, $readings->calls[0]['companyId']);
    }

    public function testTheDefaultThresholdIsSevenDays(): void
    {
        $queue = new FakeWhatsAppDeliveryQueue();
        $readings = $this->readings()->withItems([
            $this->item(11, 'AB499OK', 'AB499OK', 'ISMAEL HORACIO DUBE', '2026-10-12 09:58:00', 1002337),
        ]);

        $this->useCase($this->settings(), $readings, $queue)->execute();

        self::assertSame('2026-10-16 00:00:00', $readings->calls[0]['cutoff']);
    }

    // -------------------------------------------------------------------
    // 11. Aislamiento entre empresas
    // -------------------------------------------------------------------

    public function testEachCompanyOnlyReportsItsOwnEquipment(): void
    {
        $queue = new FakeWhatsAppDeliveryQueue();
        $readings = $this->readings()->withItems([
            $this->item(11, 'AB499OK', 'AB499OK', 'ISMAEL HORACIO DUBE', '2026-10-12 09:58:00', 1002337),
        ]);
        $settings = (new FakeManagementReadingReportSettings())
            ->withCompany(5)
            ->withCompany(9, ['phone' => '5511987654321']);

        $this->useCase($settings, $readings, $queue)->execute();

        self::assertCount(2, $queue->enqueued);
        self::assertSame(5, $queue->enqueued[0]['companyId']);
        self::assertSame(9, $queue->enqueued[1]['companyId']);
        self::assertSame('5511987654321', $queue->enqueued[1]['phone']);
    }

    // -------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------

    private function clock(): FixedNotificationClock
    {
        return new FixedNotificationClock(new DateTimeImmutable(self::NOW, new DateTimeZone('UTC')));
    }

    private function settings(): FakeManagementReadingReportSettings
    {
        return (new FakeManagementReadingReportSettings())->withCompany(5);
    }

    private function readings(): FakeReadingComplianceReadModel
    {
        return new FakeReadingComplianceReadModel();
    }

    /**
     * @return array{equipmentId:int,code:string,plate:string,driver:string,lastReadingAt:?string,lastKm:?int}
     */
    private function item(int $daysSince, string $code, string $plate, string $driver, string $lastReadingAt, int $lastKm): array
    {
        return [
            'equipmentId' => 1,
            'code' => $code,
            'plate' => $plate,
            'driver' => $driver,
            'lastReadingAt' => $lastReadingAt,
            'lastKm' => $lastKm,
        ];
    }

    /**
     * @param array<string,mixed> $globalSettings
     */
    private function useCase(
        FakeManagementReadingReportSettings $settings,
        FakeReadingComplianceReadModel $readings,
        FakeWhatsAppDeliveryQueue $queue,
        ?FixedNotificationClock $clock = null,
        array $globalSettings = [],
    ): ScheduleManagementReadingReport {
        return new ScheduleManagementReadingReport(
            $clock ?? $this->clock(),
            $settings,
            $readings,
            new ReadingComplianceReportMessageBuilder(),
            $queue,
            new RecordingWhatsAppGateway(),
            new FakeGlobalNotificationSettingsStore($globalSettings + ['whatsapp_pilot_enabled' => false]),
            7,
            self::CONTROL_URL,
        );
    }
}
