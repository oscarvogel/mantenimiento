<?php

declare(strict_types=1);

use App\Application\Identity\ActorContext;
use App\Application\Trips\GenerateDriverSettlementPdf;
use App\Application\Trips\GetDriverSettlement;
use App\Application\Trips\GetOperationalTrip;
use App\Application\Trips\OperationalTripFilters;
use App\Application\Trips\Port\DriverSettlementPdfRenderer;
use App\Application\Trips\Port\OperationalTripReadModel;
use App\Infrastructure\Trips\DriverSettlementPdfGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Vista y PDF «Liquidación del chofer» (#533).
 *
 * El ejemplo del issue: facturación ARS 6.000.000 y base de liquidación
 * ARS 5.100.000. El chofer ve los 5.100.000 y lo que le corresponde; nunca
 * los 6.000.000 ni la diferencia.
 */
final class GetDriverSettlementTest extends TestCase
{
    private const PERMISSIONS = ['viajes.ver', 'viajes.liquidacion_chofer.ver'];

    public function testDriverViewKeepsTheSettlementAndDropsEveryCommercialValue(): void
    {
        $statement = $this->statement();

        self::assertSame(5_100_000.0, $statement['totals']['base_ars']);
        self::assertSame(714_000.0, $statement['totals']['commission_ars']);
        self::assertSame(714_000.0, $statement['legs'][0]['comision_ars']);
        self::assertSame('Monto acordado', $statement['legs'][0]['modo_label']);

        $json = json_encode($statement, JSON_THROW_ON_ERROR);
        foreach (['facturacion', 'invoice', 'margin', 'margen', 'cliente', 'assigned_costs', 'operating_cost', 'observaciones', 'Nota interna', 'Cliente Sur'] as $forbidden) {
            self::assertStringNotContainsStringIgnoringCase($forbidden, $json, $forbidden . ' no debe llegar a la vista del chofer.');
        }
        self::assertStringNotContainsString('6000000', $json);
        self::assertStringNotContainsString('900000', $json, 'La diferencia entre facturación y liquidación no se expone.');
    }

    public function testAdministrativeViewStillHasTheCommercialValues(): void
    {
        $detail = (new GetOperationalTrip(new DriverSettlementReadModelFake($this->trip())))->execute($this->actor(), 55);

        self::assertSame(6_000_000.0, $detail['totals']['invoice_ars']);
        self::assertNotNull($detail['totals']['margin_ars']);
        self::assertTrue($detail['canViewDriverSettlement']);
        self::assertSame(base_url('viajes/operativos/55/liquidacion-chofer'), $detail['links']['driverSettlement']);
    }

    public function testPerTonneAndAgreedAmountLegsCarryTheirOrigin(): void
    {
        $statement = $this->statement([
            'legs' => [
                $this->leg(1, 2_000_000, 1_350_000, ['modo_liquidacion' => 'POR_TONELADA', 'toneladas_liquidacion' => 30.0, 'precio_unitario_liquidacion' => 45_000.0]),
                $this->leg(2, 3_000_000, 1_250_000, ['toneladas_liquidacion' => 30.0, 'precio_unitario_liquidacion' => 1.0]),
            ],
        ]);

        self::assertSame('Por toneladas', $statement['legs'][0]['modo_label']);
        self::assertSame(30.0, $statement['legs'][0]['toneladas']);
        self::assertSame(45_000.0, $statement['legs'][0]['precio_unitario']);
        self::assertSame('Monto acordado', $statement['legs'][1]['modo_label']);
        // A un monto acordado no se le atribuyen toneladas ni precio.
        self::assertNull($statement['legs'][1]['toneladas']);
        self::assertNull($statement['legs'][1]['precio_unitario']);
        self::assertSame(2_600_000.0, $statement['totals']['base_ars']);
        self::assertSame(364_000.0, $statement['totals']['commission_ars']);
    }

    public function testChangingTheInvoiceDoesNotChangeTheDriverCommission(): void
    {
        $low = $this->statement(['legs' => [$this->leg(1, 1_000_000, 5_100_000)]]);
        $high = $this->statement(['legs' => [$this->leg(1, 9_000_000, 5_100_000)]]);

        self::assertSame($low['totals']['commission_ars'], $high['totals']['commission_ars']);
        self::assertSame($low['totals']['total_ars'], $high['totals']['total_ars']);
    }

    public function testDifferentCurrenciesShowOnlyThePactedLiquidationRate(): void
    {
        $statement = $this->statement(['legs' => [$this->leg(1, 7_500_000, 1_260_000, [
            'moneda' => 'USD', 'cotizacion' => 1500.0, 'flete_facturacion' => 5_000.0,
            'moneda_liquidacion' => 'BRL', 'cotizacion_liquidacion' => 210.0, 'flete_liquidacion' => 6_000.0,
        ])]]);

        $leg = $statement['legs'][0];
        self::assertSame('BRL', $leg['moneda']);
        self::assertSame(6_000.0, $leg['importe']);
        self::assertSame(210.0, $leg['cotizacion']);
        self::assertSame(1_260_000.0, $leg['importe_ars']);
        $json = json_encode($statement, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('"cotizacion":1500', $json, 'La cotización de facturación es interna.');
        self::assertStringNotContainsString('USD', $json);
    }

    public function testPesosNeedNoRate(): void
    {
        self::assertNull($this->statement()['legs'][0]['cotizacion']);
    }

    public function testAdvancesExpensesAndFinalBalance(): void
    {
        $statement = $this->statement();

        // 714.000 comisión + 20.000 haberes + 15.000 gastos del chofer
        // + 30.000 gastos con caja + 5.000 devolución - 100.000 anticipo.
        self::assertSame(20_000.0, $statement['totals']['other_earnings_ars']);
        self::assertSame(15_000.0, $statement['totals']['driver_expenses_ars']);
        self::assertSame(30_000.0, $statement['totals']['cash_expenses_ars']);
        self::assertSame(100_000.0, $statement['totals']['advances_ars']);
        self::assertSame(5_000.0, $statement['totals']['returns_ars']);
        self::assertSame(684_000.0, $statement['totals']['total_ars']);
        self::assertCount(1, $statement['driverExpenses']);
        self::assertCount(1, $statement['cashExpenses']);
        self::assertCount(2, $statement['cashMovements']);
        self::assertSame('Anticipo', $statement['cashMovements'][0]['tipo_label']);
    }

    public function testOnlyTheResponsibleDriverEarningsAreShown(): void
    {
        $statement = $this->statement();

        self::assertCount(1, $statement['otherEarnings']);
        self::assertSame('Estadía', $statement['otherEarnings'][0]['concepto']);
        self::assertStringNotContainsString('Otro chofer', json_encode($statement, JSON_THROW_ON_ERROR));
    }

    public function testSeveralDriversShowNoSettlementAndNoEarnings(): void
    {
        $statement = $this->statement(['assignments' => [
            ['rol' => 'CHOFER', 'empleado_id' => 9, 'porcentaje_liquidacion' => 14.0, 'label' => 'Pérez, Juan'],
            ['rol' => 'CHOFER', 'empleado_id' => 10, 'porcentaje_liquidacion' => 14.0, 'label' => 'Gómez, Ana'],
        ]]);

        self::assertFalse($statement['available']);
        self::assertNull($statement['driver']);
        self::assertSame([], $statement['otherEarnings']);
        self::assertStringContainsString('más de un chofer', $statement['pending']);
    }

    public function testDraftBeforeClosingAndDefinitiveAfter(): void
    {
        self::assertSame('BORRADOR', $this->statement(['status' => 'EN_CURSO'])['status']);
        self::assertSame('DEFINITIVA', $this->statement(['status' => 'FINALIZADO'])['status']);
    }

    public function testCancelledTripHasNoSettlement(): void
    {
        $this->expectException(DomainException::class);

        $this->statement(['status' => 'CANCELADO']);
    }

    public function testRequiresTheSpecificPermission(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('liquidación del chofer');

        (new GetDriverSettlement(new GetOperationalTrip(new DriverSettlementReadModelFake($this->trip()))))
            ->execute(new ActorContext(1, 5, false, false, [], ['viajes.ver', 'viajes.operar'], [10]), 55);
    }

    public function testRequiresTripReadPermissionToo(): void
    {
        $this->expectException(DomainException::class);

        (new GetDriverSettlement(new GetOperationalTrip(new DriverSettlementReadModelFake($this->trip()))))
            ->execute(new ActorContext(1, 5, false, false, [], ['viajes.liquidacion_chofer.ver'], [10]), 55);
    }

    public function testSuperAdminWithoutCompanyIsRejected(): void
    {
        $this->expectException(DomainException::class);

        (new GetDriverSettlement(new GetOperationalTrip(new DriverSettlementReadModelFake($this->trip()))))
            ->execute(new ActorContext(1, null, true, true, [], self::PERMISSIONS, []), 55);
    }

    public function testQueriesWithinCompanyAndBranchScopeAndHidesOtherTrips(): void
    {
        $readModel = new DriverSettlementReadModelFake(null);
        try {
            (new GetDriverSettlement(new GetOperationalTrip($readModel)))->execute($this->actor(), 55);
            self::fail('Un viaje fuera de alcance no existe para el actor.');
        } catch (DomainException $exception) {
            self::assertStringContainsString('no existe o no está autorizado', $exception->getMessage());
        }
        self::assertSame([5, [10], 55], $readModel->query);
    }

    public function testHistoricalLegsWithoutModeKeepTheirAmounts(): void
    {
        $leg = $this->leg(1, 6_000_000, 5_100_000);
        unset($leg['modo_liquidacion'], $leg['toneladas_liquidacion'], $leg['precio_unitario_liquidacion']);
        $statement = $this->statement(['status' => 'FINALIZADO', 'legs' => [$leg]]);

        self::assertSame('MONTO_ACORDADO', $statement['legs'][0]['modo']);
        self::assertSame(5_100_000.0, $statement['legs'][0]['importe_ars']);
        self::assertSame(714_000.0, $statement['totals']['commission_ars']);
    }

    public function testPdfReceivesOnlyTheSafeProjection(): void
    {
        $renderer = new CapturingDriverSettlementRenderer();
        $useCase = new GenerateDriverSettlementPdf(
            new GetDriverSettlement(new GetOperationalTrip(new DriverSettlementReadModelFake($this->trip(['status' => 'FINALIZADO'])))),
            $renderer,
        );

        self::assertSame('%PDF-fake', $useCase->execute($this->actor(), 55));
        self::assertSame($this->statement(['status' => 'FINALIZADO'])['totals'], $renderer->received['totals']);
        self::assertArrayNotHasKey('settlement', $renderer->received);
        self::assertStringNotContainsString('6000000', json_encode($renderer->received, JSON_THROW_ON_ERROR));
    }

    public function testPdfIsRefusedWhenTheSettlementCannotBeCalculated(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('porcentaje');

        (new GenerateDriverSettlementPdf(
            new GetDriverSettlement(new GetOperationalTrip(new DriverSettlementReadModelFake($this->trip([
                'assignments' => [['rol' => 'CHOFER', 'empleado_id' => 9, 'porcentaje_liquidacion' => null, 'master_percentage' => null, 'label' => 'Pérez, Juan']],
            ])))),
            new CapturingDriverSettlementRenderer(),
        ))->execute($this->actor(), 55);
    }

    public function testRenderedPdfShowsTheBaseAndNotTheInvoice(): void
    {
        $statement = $this->statement(['status' => 'FINALIZADO', 'legs' => [
            $this->leg(1, 6_000_000, 5_100_000),
            $this->leg(2, 2_000_000, 1_350_000, ['modo_liquidacion' => 'POR_TONELADA', 'toneladas_liquidacion' => 30.0, 'precio_unitario_liquidacion' => 45_000.0]),
        ]]);
        $text = $this->pdfText((new DriverSettlementPdfGenerator())->render($statement));

        self::assertStringContainsString('5.100.000,00', $text);
        self::assertStringContainsString('Monto acordado', $text);
        self::assertStringContainsString('Por toneladas', $text);
        self::assertStringContainsString('30,000 t x ARS 45.000,00', $text);
        self::assertStringContainsString('Saldo final de liquidaci', $text);
        self::assertStringContainsString('Firma del chofer', $text);
        self::assertStringNotContainsString('6.000.000', $text);
        self::assertStringNotContainsString('2.000.000', $text);
        self::assertStringNotContainsStringIgnoringCase('factura', $text);
        self::assertStringNotContainsStringIgnoringCase('margen', $text);
        self::assertStringNotContainsString('$ ', $text, 'Los importes llevan código ISO.');
    }

    public function testRenderedDraftPdfIsMarkedAndHasNoSignatures(): void
    {
        $text = $this->pdfText((new DriverSettlementPdfGenerator())->render($this->statement(['status' => 'EN_CURSO'])));

        self::assertStringContainsString('BORRADOR', $text);
        self::assertStringNotContainsString('Firma del chofer', $text);
    }

    private function actor(): ActorContext
    {
        return new ActorContext(1, 5, false, false, [], self::PERMISSIONS, [10]);
    }

    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    private function statement(array $overrides = []): array
    {
        return (new GetDriverSettlement(new GetOperationalTrip(new DriverSettlementReadModelFake($this->trip($overrides)))))
            ->execute($this->actor(), 55);
    }

    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    private function trip(array $overrides = []): array
    {
        $status = $overrides['status'] ?? 'EN_CURSO';
        unset($overrides['status']);

        return array_replace([
            'company' => ['name' => 'Transporte Mariela'],
            'trip' => ['id' => 55, 'numero' => 42, 'estado' => $status, 'estado_label' => $status, 'sucursal' => 'Argentina', 'fecha_salida_real' => '2026-11-01', 'fecha_regreso_real' => $status === 'FINALIZADO' ? '2026-11-05' : null, 'observaciones' => 'Nota interna'],
            'assignments' => [
                ['rol' => 'TRACTOR', 'label' => 'AA111AA · T01'],
                ['rol' => 'CHOFER', 'empleado_id' => 9, 'porcentaje_liquidacion' => 14.0, 'label' => 'Pérez, Juan'],
            ],
            'legs' => [$this->leg(1, 6_000_000, 5_100_000)],
            'fuel' => [['importe_ars' => 400_000.0, 'litros' => 300.0, 'kilometraje' => null, 'tramo_id' => 1]],
            'expenses' => [
                ['fecha' => '2026-11-02', 'importe' => 15_000.0, 'moneda' => 'ARS', 'importe_ars' => 15_000.0, 'pagado_por' => 'CHOFER', 'categoria' => 'Peaje', 'comprobante' => 'T-1', 'observaciones' => 'Nota interna', 'tramo_id' => null],
                ['fecha' => '2026-11-02', 'importe' => 30_000.0, 'moneda' => 'ARS', 'importe_ars' => 30_000.0, 'pagado_por' => 'CAJA', 'categoria' => 'Comida', 'comprobante' => null, 'tramo_id' => null],
                ['fecha' => '2026-11-03', 'importe' => 80_000.0, 'moneda' => 'ARS', 'importe_ars' => 80_000.0, 'pagado_por' => 'EMPRESA', 'categoria' => 'Gomería', 'comprobante' => 'F-9', 'tramo_id' => null],
            ],
            'cashMovements' => [
                ['fecha' => '2026-11-01', 'tipo' => 'ANTICIPO', 'importe' => 100_000.0, 'moneda' => 'ARS', 'importe_ars' => 100_000.0, 'observaciones' => 'Nota interna'],
                ['fecha' => '2026-11-05', 'tipo' => 'DEVOLUCION', 'importe' => 5_000.0, 'moneda' => 'ARS', 'importe_ars' => 5_000.0],
            ],
            'otherEarnings' => [
                ['empleado_id' => 9, 'fecha' => '2026-11-02', 'concepto' => 'Estadía', 'importe' => 20_000.0, 'moneda' => 'ARS', 'importe_ars' => 20_000.0, 'observaciones' => 'Nota interna'],
                ['empleado_id' => 77, 'fecha' => '2026-11-02', 'concepto' => 'Otro chofer', 'importe' => 50_000.0, 'moneda' => 'ARS', 'importe_ars' => 50_000.0],
            ],
        ], $overrides);
    }

    /** @param array<string,mixed> $extra @return array<string,mixed> */
    private function leg(int $order, float $invoiceArs, float $liquidationArs, array $extra = []): array
    {
        return array_replace([
            'id' => $order,
            'orden' => $order,
            'origen' => 'Rosario',
            'destino' => 'Córdoba',
            'cliente' => 'Cliente Sur',
            'moneda' => 'ARS',
            'cotizacion' => 1.0,
            'flete_facturacion' => $invoiceArs,
            'flete_facturacion_ars' => $invoiceArs,
            'moneda_liquidacion' => 'ARS',
            'cotizacion_liquidacion' => 1.0,
            'flete_liquidacion' => $liquidationArs,
            'flete_liquidacion_ars' => $liquidationArs,
            'modo_liquidacion' => 'MONTO_ACORDADO',
            'toneladas_liquidacion' => null,
            'precio_unitario_liquidacion' => null,
            'observaciones' => 'Nota interna',
        ], $extra);
    }

    /** Texto de un PDF de FPDF, con los streams descomprimidos. */
    private function pdfText(string $pdf): string
    {
        $text = '';
        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $matches);
        foreach ($matches[1] as $stream) {
            $inflated = @gzuncompress($stream);
            $text .= $inflated === false ? $stream : $inflated;
        }

        return (string) iconv('windows-1252', 'UTF-8//IGNORE', $text);
    }
}

final class DriverSettlementReadModelFake implements OperationalTripReadModel
{
    public array $query = [];

    /** @param array<string,mixed>|null $detail */
    public function __construct(private ?array $detail)
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

        return $this->detail;
    }

    public function equipmentOptions(int $companyId, ?array $branchIds): array
    {
        return [];
    }
}

final class CapturingDriverSettlementRenderer implements DriverSettlementPdfRenderer
{
    /** @var array<string,mixed> */
    public array $received = [];

    public function render(array $statement): string
    {
        $this->received = $statement;

        return '%PDF-fake';
    }
}
