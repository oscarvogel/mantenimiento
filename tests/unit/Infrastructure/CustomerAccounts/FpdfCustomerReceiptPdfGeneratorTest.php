<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\CustomerAccounts;

use App\Infrastructure\CustomerAccounts\FpdfCustomerReceiptPdfGenerator;
use PHPUnit\Framework\TestCase;

final class FpdfCustomerReceiptPdfGeneratorTest extends TestCase
{
    public function testConfirmedReceiptIsRenderedAsAPdfWithoutFiscalClaims(): void
    {
        $pdf = (new FpdfCustomerReceiptPdfGenerator())->render($this->receipt('CONFIRMADO'));

        self::assertStringStartsWith('%PDF', $pdf);
        self::assertGreaterThan(2_000, strlen($pdf));
    }

    public function testAnnulledReceiptIsRenderedWithItsAnnulment(): void
    {
        $pdf = (new FpdfCustomerReceiptPdfGenerator())->render($this->receipt('ANULADO'));

        self::assertStringStartsWith('%PDF', $pdf);
        self::assertGreaterThan(2_000, strlen($pdf));
    }

    /** @return array<string,mixed> */
    private function receipt(string $state): array
    {
        return [
            'receipt' => [
                'id' => 5,
                'numero_visible' => 'R-000005',
                'estado' => $state,
                'estado_label' => $state === 'ANULADO' ? 'Anulado' : 'Confirmado',
                'fecha_emision' => '2026-11-20',
                'fecha_recepcion' => '2026-11-20',
                'observaciones' => 'Cobranza combinada',
                'usuario' => 'Ana',
                'total_ars' => 2_250_000.0,
                'acreditado_ars' => 750_000.0,
                'pendiente_ars' => 1_500_000.0,
                'imputado_ars' => 500_000.0,
                'disponible_ars' => 250_000.0,
                'saldo_anterior_ars' => 5_000_000.0,
                'saldo_posterior_ars' => 4_250_000.0,
                'saldo_posterior_previsto_ars' => 2_750_000.0,
                'motivo_anulacion' => $state === 'ANULADO' ? 'El cliente devolvió el pago' : null,
            ],
            'company' => ['razon_social' => 'Transporte Sur SA', 'nombre_fantasia' => 'Transporte Sur', 'cuit' => '30-71234567-8'],
            'customer' => ['nombre' => 'Grupo Pluma', 'identificacion_fiscal' => '30-11111111-1', 'direccion' => 'Ruta 12 km 4'],
            'branch' => ['nombre' => 'Argentina'],
            'instruments' => [
                [
                    'medio' => 'EFECTIVO', 'medio_label' => 'Efectivo', 'moneda' => 'ARS', 'importe' => 500_000.0,
                    'cotizacion' => 1.0, 'importe_ars' => 500_000.0, 'estado_acreditacion' => 'ACREDITADO', 'cheque' => null,
                    'banco' => null, 'referencia' => null, 'fecha_transferencia' => null, 'verification_label' => null, 'observaciones' => null,
                ],
                [
                    'medio' => 'CHEQUE', 'medio_label' => 'Cheque físico', 'moneda' => 'ARS', 'importe' => 1_500_000.0,
                    'cotizacion' => 1.0, 'importe_ars' => 1_500_000.0, 'estado_acreditacion' => 'PENDIENTE',
                    'cheque' => ['numero' => '000123', 'banco' => 'Galicia', 'librador' => 'Grupo Pluma', 'fecha_pago' => '2026-12-01', 'estado_label' => 'En cartera'],
                    'banco' => 'Galicia', 'referencia' => null, 'fecha_transferencia' => null, 'verification_label' => null, 'observaciones' => null,
                ],
            ],
            'imputations' => [
                ['documento' => 'Memo autorizado M-77', 'documento_fecha' => '2026-11-10', 'estado' => 'VIGENTE', 'importe_ars' => 500_000.0],
            ],
        ];
    }
}
