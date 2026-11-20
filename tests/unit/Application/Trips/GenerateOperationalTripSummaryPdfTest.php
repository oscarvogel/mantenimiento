<?php

declare(strict_types=1);

use App\Application\Identity\ActorContext;
use App\Application\Trips\GenerateOperationalTripSummaryPdf;
use App\Application\Trips\GetOperationalTrip;
use App\Application\Trips\OperationalTripFilters;
use App\Application\Trips\Port\OperationalTripReadModel;
use App\Application\Trips\Port\OperationalTripSummaryPdfRenderer;
use PHPUnit\Framework\TestCase;

final class GenerateOperationalTripSummaryPdfTest extends TestCase
{
    public function testRendersFinalizedTripWithinActorScope(): void
    {
        $readModel = new SummaryPdfReadModelFake('FINALIZADO');
        $renderer = new SummaryPdfRendererFake();
        $actor = new ActorContext(12, 5, false, false, [], ['viajes.ver'], [10, 11]);

        $pdf = (new GenerateOperationalTripSummaryPdf(new GetOperationalTrip($readModel), $renderer))
            ->execute($actor, 42);

        self::assertSame('%PDF-example', $pdf);
        self::assertSame([5, [10, 11], 42], $readModel->query);
        self::assertSame('FINALIZADO', $renderer->trip['trip']['estado']);
    }

    public function testRendersTripInProgressAsDraft(): void
    {
        $renderer = new SummaryPdfRendererFake();
        $useCase = new GenerateOperationalTripSummaryPdf(
            new GetOperationalTrip(new SummaryPdfReadModelFake('EN_CURSO')),
            $renderer,
            static fn (): string => '2026-11-20',
        );

        self::assertSame('%PDF-example', $useCase->execute(new ActorContext(12, 5, false, false, [], ['viajes.ver'], [10]), 42));
        self::assertTrue($renderer->trip['summary']['draft']);
        self::assertSame('2026-11-20', $renderer->trip['summary']['generated_on']);
        self::assertSame(22, $renderer->trip['summary']['kpis']['days'], 'En curso cuenta los días hasta hoy');
    }

    public function testFinalizedTripIsNotADraftAndCarriesItsIndicators(): void
    {
        $renderer = new SummaryPdfRendererFake();
        (new GenerateOperationalTripSummaryPdf(
            new GetOperationalTrip(new SummaryPdfReadModelFake('FINALIZADO')),
            $renderer,
            static fn (): string => '2026-11-20',
        ))->execute(new ActorContext(12, 5, false, false, [], ['viajes.ver'], [10]), 42);

        self::assertFalse($renderer->trip['summary']['draft']);
        self::assertSame('ODOMETRO_VIAJE', $renderer->trip['summary']['consumption_source']);
        self::assertSame(1_000, $renderer->trip['summary']['kpis']['km']);
        self::assertSame(40.0, $renderer->trip['summary']['kpis']['liters_per_100_km']);
        self::assertSame(2.5, $renderer->trip['summary']['kpis']['km_per_liter']);
        self::assertSame(25.0, $renderer->trip['summary']['kpis']['tons']);
    }

    /** @dataProvider statusesWithoutRoute */
    public function testDoesNotRenderTripWithoutRoute(string $status): void
    {
        $renderer = new SummaryPdfRendererFake();
        $useCase = new GenerateOperationalTripSummaryPdf(
            new GetOperationalTrip(new SummaryPdfReadModelFake($status)),
            $renderer,
        );
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('en curso o finalizado');

        try {
            $useCase->execute(new ActorContext(12, 5, false, false, [], ['viajes.ver'], [10]), 42);
        } finally {
            self::assertNull($renderer->trip);
        }
    }

    /** @return array<string,array{string}> */
    public static function statusesWithoutRoute(): array
    {
        return ['planificado' => ['PLANIFICADO'], 'cancelado' => ['CANCELADO']];
    }
}

final class SummaryPdfReadModelFake implements OperationalTripReadModel
{
    public array $query = [];

    public function __construct(private string $status)
    {
    }

    public function branches(int $companyId, ?array $branchIds): array
    {
        return [];
    }

    public function list(int $companyId, ?array $branchIds, OperationalTripFilters $filters, int $page, int $perPage): array
    {
        return ['items' => [], 'total' => 0];
    }

    public function find(int $companyId, ?array $branchIds, int $tripId): ?array
    {
        $this->query = [$companyId, $branchIds, $tripId];

        return [
            'trip' => [
                'id' => $tripId, 'numero' => 42, 'estado' => $this->status,
                'fecha_salida_real' => '2026-10-30', 'km_salida' => 100_000,
                'fecha_regreso_real' => $this->status === 'FINALIZADO' ? '2026-11-17' : null,
                'km_regreso' => $this->status === 'FINALIZADO' ? 101_000 : null,
            ],
            'assignments' => [], 'stops' => [], 'expenses' => [],
            'legs' => [
                ['id' => 1, 'orden' => 1, 'toneladas' => 25, 'flete_facturacion_ars' => null, 'flete_liquidacion_ars' => null],
            ],
            'fuel' => [
                ['id' => 1, 'litros' => 400, 'importe' => 800_000, 'importe_ars' => 800_000, 'moneda' => 'ARS', 'kilometraje' => null, 'tramo_id' => null],
            ],
            'cashMovements' => [], 'otherEarnings' => [],
        ];
    }
    public function equipmentOptions(int $companyId, ?array $branchIds): array { return []; }
}

final class SummaryPdfRendererFake implements OperationalTripSummaryPdfRenderer
{
    public ?array $trip = null;

    public function render(array $trip): string
    {
        $this->trip = $trip;

        return '%PDF-example';
    }
}
