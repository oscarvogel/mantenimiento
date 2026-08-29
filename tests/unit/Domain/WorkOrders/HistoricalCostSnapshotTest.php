<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\WorkOrders;

use App\Domain\WorkOrders\HistoricalCostSnapshot;
use DomainException;
use PHPUnit\Framework\TestCase;

final class HistoricalCostSnapshotTest extends TestCase
{
    public function testConvertsForeignAmountAndFreezesMetadata(): void
    {
        $snapshot = HistoricalCostSnapshot::fromInput(
            'brl',
            '2992.00',
            '305',
            '2026-08-28',
            'BCRA',
            '2026-08-28',
        )->toPersistence();

        self::assertSame('BRL', $snapshot['moneda_original']);
        self::assertSame('2992.00', $snapshot['importe_original']);
        self::assertSame('305.000000', $snapshot['tipo_cambio_ars']);
        self::assertSame('2026-08-28', $snapshot['fecha_tipo_cambio']);
        self::assertSame('BCRA', $snapshot['origen_tipo_cambio']);
        self::assertSame('912560.00', $snapshot['importe_ars']);
    }

    public function testArsKeepsSameHistoricalAmountWithoutExternalQuote(): void
    {
        $snapshot = HistoricalCostSnapshot::fromInput(
            'ARS',
            '125000.50',
            null,
            null,
            null,
            '2026-08-28',
        )->toPersistence();

        self::assertSame('ARS', $snapshot['moneda_original']);
        self::assertSame('1.000000', $snapshot['tipo_cambio_ars']);
        self::assertSame('125000.50', $snapshot['importe_ars']);
        self::assertSame('ARS', $snapshot['origen_tipo_cambio']);
    }

    public function testRejectsForeignCurrencyWithoutHistoricalQuote(): void
    {
        $this->expectException(DomainException::class);
        HistoricalCostSnapshot::fromInput('USD', '100', null, null, null, '2026-08-28');
    }

    public function testRejectsQuoteDateAfterServiceDate(): void
    {
        $this->expectException(DomainException::class);
        HistoricalCostSnapshot::fromInput('BRL', '100', '300', '2026-08-29', 'manual', '2026-08-28');
    }
}
