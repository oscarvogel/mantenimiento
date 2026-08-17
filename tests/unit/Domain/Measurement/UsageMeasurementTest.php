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

    public function testAcceptsCommaDecimalAndNormalizesHoursToTenths(): void
    {
        self::assertSame(12500, UsageMeasurement::parseHours('1250'));
        self::assertSame(12505, UsageMeasurement::parseHours('1250.5'));
        self::assertSame(12505, UsageMeasurement::parseHours('1250,5'));
    }

    public function testUsesHumanErrorForInvalidHourFormat(): void
    {
        try {
            UsageMeasurement::parseHours('1250.55');
            self::fail('El formato inválido debía rechazarse.');
        } catch (DomainException $exception) {
            self::assertSame('El horómetro debe ser un número positivo con un decimal como máximo. Podés usar coma o punto.', $exception->getMessage());
        }
    }

    /** @dataProvider invalidHourFormats */
    public function testRejectsAmbiguousOrOverPreciseHourFormats(string $value): void
    {
        $this->expectException(DomainException::class);
        UsageMeasurement::parseHours($value);
    }

    /** @return array<string, array{string}> */
    public static function invalidHourFormats(): array
    {
        return [
            'two decimals with dot' => ['1250.55'],
            'two decimals with comma' => ['1250,55'],
            'mixed separators comma' => ['1.250,5'],
            'mixed separators point' => ['1,250.5'],
            'negative' => ['-1'],
        ];
    }

    public function testKeepsHoursAsExactTenths(): void
    {
        $measurement = UsageMeasurement::from(1500, '123.4');

        self::assertSame(1500, $measurement->kilometers());
        self::assertSame(1234, $measurement->hoursTenths());
        self::assertSame('123.4', $measurement->hours());
    }
}
