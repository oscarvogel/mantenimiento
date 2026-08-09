<?php

declare(strict_types=1);

use App\Domain\Measurement\UsageMeasurement;
use PHPUnit\Framework\TestCase;

final class UsageMeasurementTest extends TestCase
{
    public function testRequiresAtLeastOneValue(): void
    {
        $this->expectException(DomainException::class);
        UsageMeasurement::from(null, null);
    }

    public function testRejectsNegativeValuesAndMoreThanOneHourDecimal(): void
    {
        try {
            UsageMeasurement::from(-1, null);
            self::fail('El kilometraje negativo debió rechazarse.');
        } catch (DomainException) {
            self::assertTrue(true);
        }

        $this->expectException(DomainException::class);
        UsageMeasurement::from(null, '10.25');
    }

    public function testKeepsHoursAsExactTenths(): void
    {
        $measurement = UsageMeasurement::from(1500, '123.4');

        self::assertSame(1500, $measurement->kilometers());
        self::assertSame(1234, $measurement->hoursTenths());
        self::assertSame('123.4', $measurement->hours());
    }
}
