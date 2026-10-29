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

    public function testDoesNotRenderTripThatIsStillInProgress(): void
    {
        $renderer = new SummaryPdfRendererFake();
        $useCase = new GenerateOperationalTripSummaryPdf(
            new GetOperationalTrip(new SummaryPdfReadModelFake('EN_CURSO')),
            $renderer,
        );
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('solo se puede descargar');

        try {
            $useCase->execute(new ActorContext(12, 5, false, false, [], ['viajes.ver'], [10]), 42);
        } finally {
            self::assertNull($renderer->trip);
        }
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
            'trip' => ['id' => $tripId, 'numero' => 42, 'estado' => $this->status],
            'assignments' => [], 'legs' => [], 'stops' => [], 'fuel' => [], 'expenses' => [],
            'cashMovements' => [], 'otherEarnings' => [],
        ];
    }
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
