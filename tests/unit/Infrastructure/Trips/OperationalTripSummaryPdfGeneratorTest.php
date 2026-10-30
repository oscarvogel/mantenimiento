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
    }
}
