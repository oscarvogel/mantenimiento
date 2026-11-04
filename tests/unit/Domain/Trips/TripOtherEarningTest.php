<?php

declare(strict_types=1);

use App\Domain\Trips\TripMoney;
use App\Domain\Trips\TripOtherEarning;
use PHPUnit\Framework\TestCase;

final class TripOtherEarningTest extends TestCase
{
    public function testKeepsDriverDateMoneyAndConcept(): void
    {
        $earning = new TripOtherEarning(
            7,
            new DateTimeImmutable('2026-10-20'),
            new TripMoney(25_000.0, 'ARS', 1.0),
            '  Espera en descarga  ',
        );

        self::assertSame(7, $earning->employeeId());
        self::assertSame('Espera en descarga', $earning->concept());
        self::assertSame(25_000.0, $earning->money()->amount());
    }

    public function testRejectsNonPositiveAmount(): void
    {
        $this->expectException(DomainException::class);

        new TripOtherEarning(7, new DateTimeImmutable(), new TripMoney(0.0, 'ARS', 1.0), 'Espera');
    }

    public function testRejectsEmptyConcept(): void
    {
        $this->expectException(DomainException::class);

        new TripOtherEarning(7, new DateTimeImmutable(), new TripMoney(10.0, 'ARS', 1.0), '  ');
    }

    public function testKeepsCatalogConceptQuantityAndUnitAmount(): void
    {
        $earning = new TripOtherEarning(
            7,
            new DateTimeImmutable('2026-10-20'),
            new TripMoney(75_000.0, 'ARS', 1.0),
            'Estadía Sider',
            null,
            9,
            1.5,
            50_000.0,
        );

        self::assertSame(9, $earning->conceptId());
        self::assertSame(1.5, $earning->quantity());
        self::assertSame(50_000.0, $earning->unitAmount());
    }

    public function testRejectsNonPositiveQuantity(): void
    {
        $this->expectException(DomainException::class);

        new TripOtherEarning(
            7,
            new DateTimeImmutable(),
            new TripMoney(10.0, 'ARS', 1.0),
            'Estadía Sider',
            null,
            9,
            0.0,
        );
    }
}
