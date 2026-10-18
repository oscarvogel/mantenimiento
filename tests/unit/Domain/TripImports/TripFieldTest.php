<?php

declare(strict_types=1);

use App\Domain\TripImports\TripField;
use App\Domain\TripImports\TripKey;
use PHPUnit\Framework\TestCase;

final class TripFieldTest extends TestCase
{
    public function testNormalizesLegacyPaddingAndNullMarker(): void
    {
        self::assertSame('AF 117 ZK', TripField::text('AF 117 ZK   ', 10)['value']);
        self::assertNull(TripField::text('\\N', 10)['value']);
    }

    public function testTextTruncatesWithWarning(): void
    {
        $result = TripField::text(str_repeat('x', 12), 10);

        self::assertSame(str_repeat('x', 10), $result['value']);
        self::assertNotNull($result['warning']);
        self::assertNull($result['error']);
    }

    public function testDateRejectsOverflowAndInvalidFormat(): void
    {
        self::assertSame('2022-01-13', TripField::date('2022-01-13')['value']);
        self::assertNull(TripField::date('2022-02-31', true)['value']);
        self::assertNotNull(TripField::date('2022-02-31', true)['error']);
        self::assertNull(TripField::date('13/01/2022')['value']);
    }

    public function testUnsignedIntRespectsMinimum(): void
    {
        self::assertSame(0, TripField::unsignedInt('0')['value']);
        self::assertSame(5, TripField::unsignedInt('5')['value']);
        self::assertNotNull(TripField::unsignedInt('-1')['error']);
        self::assertNull(TripField::unsignedInt('1', true, 1)['error']);
        self::assertNotNull(TripField::unsignedInt('0', true, 1)['error']);
    }

    public function testDecimalUsesConfiguredScale(): void
    {
        self::assertSame('14.00', TripField::decimal('14.00', false, 2)['value']);
        self::assertNotNull(TripField::decimal('14.001', false, 2)['error']);
        self::assertSame('1.234567', TripField::decimal('1.234567', false, 6)['value']);
        self::assertNull(TripField::decimal('1.234,56')['value']);
    }

    public function testCuitNormalizesDigitsAndKeepsFormattedValue(): void
    {
        self::assertSame('23-28059786-4', TripField::cuit('23280597864')['value']);
        self::assertSame('23-28059786-4', TripField::cuit('23-28059786-4')['value']);
        self::assertNotNull(TripField::cuit('AA')['error']);
    }

    public function testDomainKeepsInternalSpaceAndTrims(): void
    {
        self::assertSame('AC 532 DD', TripField::domain(' AC   532 DD ')['value']);
        self::assertSame('IUU5G88', TripField::domain('iuu5g88')['value']);
    }

    public function testTripKeyRequiresAllParts(): void
    {
        self::assertSame('23-28059786-4|AC 532 DD|1', TripKey::make('23-28059786-4', 'AC 532 DD', 1));
        self::assertSame('', TripKey::make('', 'AC 532 DD', 1));
        self::assertSame('', TripKey::make('23-28059786-4', '', 1));
        self::assertSame('', TripKey::make('23-28059786-4', 'AC 532 DD', 0));
    }
}
