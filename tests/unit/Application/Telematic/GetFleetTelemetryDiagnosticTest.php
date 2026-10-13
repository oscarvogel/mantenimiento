<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Telematic;

use App\Application\Identity\ActorContext;
use App\Application\Notifications\Port\NotificationClock;
use App\Application\Telematic\CalcularConsumoPorEquipo;
use App\Application\Telematic\GetFleetTelemetryDiagnostic;
use App\Application\Telematic\Port\ConsumoConfigStore;
use App\Application\Telematic\Port\FleetTelemetryBoardReader;
use App\Application\Telematic\Port\SerieMuestrasReader;
use App\Domain\Telematic\Consumo\ConsumoConfigEmpresa;
use App\Domain\Telematic\InstantaneaEquipo;
use App\Domain\Telematic\MuestraEquipo;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PHPUnit\Framework\TestCase;

final class GetFleetTelemetryDiagnosticTest extends TestCase
{
    public function testDevuelveCapturasConSusDosHorasYElCalculoDelMismoDia(): void
    {
        $sample = new MuestraEquipo(
            7,
            5,
            42,
            '28396459',
            'wialon',
            new InstantaneaEquipo(
                new DateTimeImmutable('2026-10-13 13:00:00', new DateTimeZone('UTC')),
                null,
                1710049,
                45712,
                true,
                false,
                24.5,
                220.5,
            ),
            new DateTimeImmutable('2026-10-13 13:05:00', new DateTimeZone('UTC')),
        );
        $invalidFuelSample = new MuestraEquipo(
            7,
            5,
            42,
            '28396459',
            'wialon',
            new InstantaneaEquipo(
                new DateTimeImmutable('2026-10-13 13:05:00', new DateTimeZone('UTC')),
                null,
                1710049,
                45712,
                true,
                false,
                24.5,
                -5,
            ),
            new DateTimeImmutable('2026-10-13 13:06:00', new DateTimeZone('UTC')),
        );
        $samples = new FleetDiagnosticSamplesReader([$sample, $invalidFuelSample]);
        $useCase = new GetFleetTelemetryDiagnostic(
            new FleetDiagnosticBoardReader(),
            $samples,
            new CalcularConsumoPorEquipo($samples),
            new FleetDiagnosticConfigStore(),
            new FleetDiagnosticClock('2026-10-13 14:00:00'),
        );

        $result = $useCase->execute($this->actor(), 42, '2026-10-13');

        self::assertSame('2026-10-13 10:00:00', $result['samples'][0]['observedAt']);
        self::assertSame('2026-10-13 10:05:00', $result['samples'][0]['recordedAt']);
        self::assertSame(220.5, $result['samples'][0]['fuelLiters']);
        self::assertNull($result['samples'][1]['fuelLiters']);
        self::assertSame('COMBUSTIBLE', $result['samples'][1]['anomalies'][0]['sensor']);
        self::assertSame(1, $samples->calls);
        self::assertSame('2026-10-13 00:00:00', $samples->from?->format('Y-m-d H:i:s'));
        self::assertSame('2026-10-13 23:59:59', $samples->to?->format('Y-m-d H:i:s'));
        self::assertSame([], $result['trips']);
    }

    public function testNoLeeHistoricoSiElEquipoQuedaFueraDeLasSucursalesAutorizadas(): void
    {
        $samples = new FleetDiagnosticSamplesReader([]);
        $useCase = new GetFleetTelemetryDiagnostic(
            new FleetDiagnosticBoardReader(false),
            $samples,
            new CalcularConsumoPorEquipo($samples),
            new FleetDiagnosticConfigStore(),
            new FleetDiagnosticClock('2026-10-13 14:00:00'),
        );

        try {
            $useCase->execute($this->actor(), 99, '2026-10-13');
            self::fail('El equipo fuera del alcance debe rechazarse.');
        } catch (DomainException) {
            self::assertSame(0, $samples->calls);
        }
    }

    private function actor(): ActorContext
    {
        return new ActorContext(10, 7, false, false, ['Consulta'], ['equipos.ver'], [4]);
    }
}

final class FleetDiagnosticSamplesReader implements SerieMuestrasReader
{
    public int $calls = 0;
    public ?DateTimeImmutable $from = null;
    public ?DateTimeImmutable $to = null;

    /** @param list<MuestraEquipo> $samples */
    public function __construct(private array $samples)
    {
    }

    public function serie(int $empresaId, int $equipoId, DateTimeImmutable $desde, DateTimeImmutable $hasta): array
    {
        $this->calls++;
        $this->from = $desde;
        $this->to = $hasta;

        return $this->samples;
    }
}

final class FleetDiagnosticBoardReader implements FleetTelemetryBoardReader
{
    public function __construct(private bool $containsEquipment = true)
    {
    }

    public function read(int $companyId, ?array $branchIds): array
    {
        return ['units' => $this->containsEquipment ? [['equipmentId' => 42]] : [], 'branches' => []];
    }
}

final class FleetDiagnosticConfigStore implements ConsumoConfigStore
{
    public function obtener(int $empresaId): ?ConsumoConfigEmpresa
    {
        return null;
    }

    public function guardar(ConsumoConfigEmpresa $config): ConsumoConfigEmpresa
    {
        return $config;
    }
}

final class FleetDiagnosticClock implements NotificationClock
{
    public function __construct(private string $time)
    {
    }

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable($this->time, new DateTimeZone('America/Argentina/Buenos_Aires'));
    }
}
