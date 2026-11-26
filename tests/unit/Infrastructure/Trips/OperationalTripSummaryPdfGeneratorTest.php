<?php

declare(strict_types=1);

use App\Infrastructure\Trips\OperationalTripSummaryPdfGenerator;
use PHPUnit\Framework\TestCase;

final class OperationalTripSummaryPdfGeneratorTest extends TestCase
{
    public function testCreatesPdfWithTripCostAndSettlementSections(): void
    {
        $pdf = (new OperationalTripSummaryPdfGenerator())->render([
            'company' => ['name' => 'TSA Argentina'],
            'trip' => [
                'numero' => 102,
                'estado' => 'FINALIZADO',
                'sucursal' => 'TSA Argentina',
                'fecha_salida_real' => '2026-08-21 08:00:00',
                'fecha_regreso_real' => '2026-09-21 18:00:00',
                'km_salida' => 479608,
                'km_regreso' => 485747,
                'observaciones' => 'Resumen de prueba',
            ],
            'assignments' => [
                ['rol' => 'TRACTOR', 'label' => 'AF 117 ZK · SCANIA-R450 A6X2'],
                ['rol' => 'CHOFER', 'label' => 'Marcelo Matluk', 'porcentaje_liquidacion' => 14],
            ],
            'legs' => [[
                'orden' => 1, 'origen' => 'AMepre BR', 'destino' => 'Buenos Aires ARG',
                'codigo_origen_destino' => 947, 'cliente_codigo' => '324',
                'cliente' => 'RUBENJUVE S.R.L.', 'km' => null, 'toneladas' => null,
                'flete_facturacion_ars' => 3_850_000, 'flete_liquidacion_ars' => 2_500_000,
            ]],
            'fuel' => [[
                'fecha' => '2026-08-31 00:00:00', 'litros' => 92, 'importe_ars' => 202_164,
                'kilometraje' => 479900, 'estacion' => 'YPF Ruta Zarate', 'comprobante' => null,
            ]],
            'expenses' => [[
                'fecha' => '2026-09-21 00:00:00', 'categoria' => 'Peaje', 'observaciones' => null,
                'importe_ars' => 47_500, 'pagado_por' => 'EMPRESA',
            ]],
            'cashMovements' => [[
                'fecha' => '2026-08-24 00:00:00', 'tipo' => 'ANTICIPO', 'importe' => 840_000,
                'moneda' => 'ARS', 'importe_ars' => 840_000, 'observaciones' => 'Anticipo de viaje',
            ]],
            'otherEarnings' => [[
                'fecha' => '2026-10-02', 'concepto' => 'Cruce', 'empleado' => 'Marcelo Matluk',
                'importe_ars' => 40_000,
            ]],
            'totals' => [
                'invoice_ars' => 3_850_000, 'fuel_liters' => 92, 'fuel_amount_ars' => 202_164,
                'expense_amount_ars' => 47_500, 'margin_ars' => 3_060_336,
                'margin_pct' => 79.49, 'km' => 6_139,
            ],
            'consumption' => ['km' => 6_139, 'liters_per_100' => 35.27],
            'summary' => [
                'draft' => false, 'generated_on' => '2026-11-20', 'consumption_source' => 'ODOMETRO_VIAJE',
                'kpis' => \App\Domain\Trips\TripKpis::calculate(6_139, 92.0, 202_164.0, 3_850_000.0, null, 3_060_336.0, null, 32),
            ],
            'settlement' => [
                'driver' => 'Marcelo Matluk', 'commission_ars' => 539_000,
                'other_earnings_ars' => 40_000, 'driver_expenses_ars' => 0,
                'cash_expenses_ars' => 0, 'advances_ars' => 840_000,
                'returns_ars' => 0, 'account_balance_ars' => -840_000,
                'total_ars' => -261_000,
            ],
        ]);

        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertStringContainsString('Resumen del viaje', $pdf);
        self::assertStringContainsString(iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', 'Liquidación del chofer'), $pdf);
        self::assertStringContainsString('Combustible', $pdf);
        self::assertStringContainsString('Margen operativo', $pdf);
        self::assertStringContainsString('Sin dato', $pdf);
        self::assertStringContainsString('Indicadores del viaje', $pdf);
        self::assertStringContainsString('km/L', $pdf);
        self::assertStringContainsString('Generado el 20/11/2026', $pdf);
        self::assertStringNotContainsString('BORRADOR', $pdf);
    }

    public function testMarksTripInProgressAsDraft(): void
    {
        $pdf = (new OperationalTripSummaryPdfGenerator())->render([
            'trip' => ['numero' => 21, 'estado' => 'EN_CURSO', 'fecha_salida_real' => '2026-10-30', 'km_salida' => 1_727_000],
            'summary' => [
                'draft' => true, 'generated_on' => '2026-11-20', 'consumption_source' => null,
                'kpis' => \App\Domain\Trips\TripKpis::calculate(null, 2_012.0, 3_118_861.0, null, null, null, null, 22),
            ],
        ]);

        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertStringContainsString('BORRADOR - Viaje en curso', $pdf);
        self::assertStringContainsString('a hoy', $pdf);
        self::assertStringContainsString(iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', 'falta') , $pdf);
    }

    public function testHistoricalCashAdvancesAreRenderedWithStoredArs(): void
    {
        $pdf = (new OperationalTripSummaryPdfGenerator())->render([
            'company' => ['name' => 'TSA Argentina'],
            'trip' => [
                'numero' => 591,
                'estado' => 'FINALIZADO',
                'sucursal' => 'TSA Argentina',
            ],
            'assignments' => [['rol' => 'CHOFER', 'label' => 'Carlos Sosa', 'porcentaje_liquidacion' => 10.0]],
            'legs' => [[
                'id' => 1, 'orden' => 1, 'origen' => 'AR', 'destino' => 'BR', 'cliente' => 'Cliente',
                'modo_liquidacion' => 'MONTO_ACORDADO', 'toneladas_liquidacion' => null, 'precio_unitario_liquidacion' => null,
                'moneda' => 'ARS', 'flete_facturacion' => 1000000.0, 'flete_facturacion_ars' => 1000000.0,
                'moneda_liquidacion' => 'ARS', 'flete_liquidacion' => 1000000.0, 'flete_liquidacion_ars' => 1000000.0,
                'commission_ars' => 100000.0, 'assigned_costs_ars' => 0.0, 'margin_ars' => 900000.0, 'margin_pct' => 90.0,
            ]],
            'fuel' => [],
            'expenses' => [],
            'cashMovements' => [
                ['fecha' => '2026-11-14', 'tipo' => 'ANTICIPO', 'importe' => 300.0, 'moneda' => 'BRL', 'cotizacion' => 290.0, 'importe_ars' => 87000.0],
                ['fecha' => '2026-11-14', 'tipo' => 'ANTICIPO', 'importe' => 150000.0, 'moneda' => 'ARS', 'cotizacion' => 1.0, 'importe_ars' => 150000.0],
            ],
            'otherEarnings' => [],
            'totals' => [
                'has_legs' => true,
                'invoice_ars' => 1000000.0,
                'liquidation_ars' => 1000000.0,
                'commission_ars' => 100000.0,
                'fuel_liters' => 0.0,
                'fuel_amount_ars' => 0.0,
                'operating_cost_ars' => 0.0,
                'margin_ars' => 900000.0,
                'margin_pct' => 90.0,
                'other_earnings_ars' => 0.0,
                'driver_expenses_ars' => 0.0,
                'cash_expenses_ars' => 0.0,
                'advances_ars' => 237000.0,
                'returns_ars' => 0.0,
                'settlement_available' => true,
            ],
            'settlement' => [
                'commission_ars' => 100000.0,
                'other_earnings_ars' => 0.0,
                'driver_expenses_ars' => 0.0,
                'cash_expenses_ars' => 0.0,
                'advances_ars' => 237000.0,
                'returns_ars' => 0.0,
                'account_balance_ars' => 0.0,
                'total_ars' => -137000.0,
            ],
        ]);

        $text = $this->pdfText($pdf);
        self::assertStringContainsString('87.000,00', $text);
        self::assertStringContainsString('150.000,00', $text);
        self::assertStringContainsString('237.000,00', $text);
        self::assertStringContainsString('BRL 300,00 x 290,00', $text);
    }

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
