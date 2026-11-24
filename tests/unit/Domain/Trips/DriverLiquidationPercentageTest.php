<?php

declare(strict_types=1);

use App\Domain\Trips\DriverLiquidationPercentage;
use PHPUnit\Framework\TestCase;

final class DriverLiquidationPercentageTest extends TestCase
{
    public function testThePercentageSavedInTheTripWins(): void
    {
        $resolved = DriverLiquidationPercentage::resolve(12.5, 14.0, 14.0);

        self::assertSame(12.5, $resolved->value());
        self::assertSame(DriverLiquidationPercentage::SOURCE_TRIP, $resolved->source());
        self::assertTrue($resolved->isResolved());
        self::assertFalse($resolved->comesFromCompanyDefault());
    }

    public function testTheMasterWinsWhenTheTripHasNoPercentage(): void
    {
        $resolved = DriverLiquidationPercentage::resolve(null, 14.0, 14.0);

        self::assertSame(14.0, $resolved->value());
        self::assertSame(DriverLiquidationPercentage::SOURCE_MASTER, $resolved->source());
        self::assertFalse($resolved->comesFromCompanyDefault());
    }

    public function testTheCompanyDefaultIsTheLastResort(): void
    {
        $resolved = DriverLiquidationPercentage::resolve(null, null, 14.0);

        self::assertSame(14.0, $resolved->value());
        self::assertSame(DriverLiquidationPercentage::SOURCE_COMPANY_DEFAULT, $resolved->source());
        self::assertTrue($resolved->comesFromCompanyDefault());
    }

    public function testWithoutAnySourceThePercentageStaysUndefined(): void
    {
        $resolved = DriverLiquidationPercentage::resolve(null, null, null);

        self::assertNull($resolved->value());
        self::assertNull($resolved->source());
        self::assertFalse($resolved->isResolved());
        self::assertFalse($resolved->comesFromCompanyDefault());
    }

    public function testThePercentageIsRoundedToTwoDecimals(): void
    {
        $resolved = DriverLiquidationPercentage::resolve(13.4567, null, null);

        self::assertSame(13.46, $resolved->value());
    }

    public function testEachSourceHasItsOwnLabel(): void
    {
        self::assertSame('del viaje', DriverLiquidationPercentage::labelFor(DriverLiquidationPercentage::SOURCE_TRIP));
        self::assertSame(
            'del maestro de porcentajes',
            DriverLiquidationPercentage::labelFor(DriverLiquidationPercentage::SOURCE_MASTER),
        );
        self::assertSame(
            'del porcentaje por defecto de la empresa',
            DriverLiquidationPercentage::labelFor(DriverLiquidationPercentage::SOURCE_COMPANY_DEFAULT),
        );
        self::assertNull(DriverLiquidationPercentage::labelFor(null));
    }
}
