<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\TheoreticalCost;

use App\Infrastructure\TheoreticalCost\BcraReferenceExchangeRate;
use DomainException;
use PHPUnit\Framework\TestCase;

final class BcraReferenceExchangeRateTest extends TestCase
{
    public function testReturnsReferenceCom3500QuoteAndPublicationDate(): void
    {
        $provider = new BcraReferenceExchangeRate(static fn (): array => [
            'results' => [
                'fecha' => '2026-10-09',
                'detalle' => [
                    ['codigoMoneda' => 'USD', 'tipoCotizacion' => 1517.0],
                    ['codigoMoneda' => 'REF', 'tipoCotizacion' => 1515.8766],
                ],
            ],
        ]);

        self::assertSame([
            'ars_por_usd' => 1515.8766,
            'fecha' => '2026-10-09',
            'fuente' => 'BCRA · Tipo de Cambio de Referencia Com. A 3500',
        ], $provider->latest());
    }

    public function testFailsClearlyWhenReferenceQuoteIsMissing(): void
    {
        $provider = new BcraReferenceExchangeRate(static fn (): array => [
            'results' => ['fecha' => '2026-10-09', 'detalle' => [
                ['codigoMoneda' => 'USD', 'tipoCotizacion' => 1517.0],
            ]],
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('El BCRA no publicó una cotización de referencia válida.');
        $provider->latest();
    }
}
