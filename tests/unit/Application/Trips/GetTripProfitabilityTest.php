<?php

declare(strict_types=1);

use App\Application\Identity\ActorContext;
use App\Application\Trips\GetTripProfitability;
use App\Application\Trips\Port\TripProfitabilityReadModel;
use App\Application\Trips\TripProfitabilityFilters;
use PHPUnit\Framework\TestCase;

final class GetTripProfitabilityTest extends TestCase
{
    public function testComputesDirectMarginAndFlagsMissingData(): void
    {
        $readModel = new ProfitabilityReadModelFake([
            $this->row(1, 10, 1_000_000, 2, 300_000, 50_000, 100_000, 500_000, 14.0),
            $this->row(2, 20, 0, 0, 100_000, 0, 0, 500_000, 14.0),
            $this->row(3, 30, 800_000, 1, 0, 0, 0, 400_000, 14.0),
        ]);

        $payload = (new GetTripProfitability($readModel))->execute(
            new ActorContext(1, 5, false, true, [], ['viajes.ver'], []),
            new TripProfitabilityFilters(),
        );

        self::assertSame(3, $payload['summary']['trips_total']);
        self::assertSame(1, $payload['summary']['trips_analyzable']);
        self::assertSame(1, $payload['summary']['trips_complete']);
        self::assertSame(1_000_000.0, $payload['summary']['revenue']);
        self::assertSame(520_000.0, $payload['summary']['direct_cost']);
        self::assertSame(480_000.0, $payload['summary']['margin']);
        self::assertSame(48.0, $payload['summary']['margin_pct']);
        self::assertSame('COMPLETO', $payload['trips'][0]['quality']);
        self::assertSame('SIN_FACTURA', $payload['trips'][1]['quality']);
        self::assertSame('SIN_COSTOS', $payload['trips'][2]['quality']);
        self::assertNull($payload['trips'][1]['margin']);
    }

    public function testRejectsActorWithoutTripPermission(): void
    {
        $this->expectException(DomainException::class);

        (new GetTripProfitability(new ProfitabilityReadModelFake([])))->execute(
            new ActorContext(1, 5, false, true, [], [], []),
            new TripProfitabilityFilters(),
        );
    }

    /** @return array<string,mixed> */
    private function row(
        int $id,
        int $number,
        float $revenue,
        int $invoiceCount,
        float $fuel,
        float $expenses,
        float $other,
        float $flete,
        float $percentage,
    ): array {
        return [
            'id' => $id,
            'numero_viaje' => $number,
            'dominio' => 'AC 532 DD',
            'sucursal_id' => 10,
            'fecha_salida' => '2026-10-20',
            'fecha_regreso' => '2026-10-22',
            'km_salida' => 100,
            'km_regreso' => 200,
            'porcentaje_chofer' => $percentage,
            'revenue' => $revenue,
            'invoice_count' => $invoiceCount,
            'fuel' => $fuel,
            'expenses' => $expenses,
            'other' => $other,
            'flete' => $flete,
        ];
    }
}

final class ProfitabilityReadModelFake implements TripProfitabilityReadModel
{
    /** @param list<array<string,mixed>> $rows */
    public function __construct(private array $rows)
    {
    }

    public function rows(int $companyId, ?array $branchIds, TripProfitabilityFilters $filters): array
    {
        return $this->rows;
    }

    public function branches(int $companyId, ?array $branchIds): array
    {
        return [['id' => 10, 'code' => 'AR', 'name' => 'Argentina']];
    }

    public function domains(int $companyId, ?array $branchIds): array
    {
        return ['AC 532 DD'];
    }
}
