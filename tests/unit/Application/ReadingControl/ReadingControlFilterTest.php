<?php

declare(strict_types=1);

use App\Application\ReadingControl\ReadingControlFilter;
use PHPUnit\Framework\TestCase;

final class ReadingControlFilterTest extends TestCase
{
    private function filter(string $key): ReadingControlFilter
    {
        return ReadingControlFilter::fromKey($key, new DateTimeImmutable('2026-09-28 10:00:00'));
    }

    private function at(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value);
    }

    public function testUnknownFilterFallsBackToAll(): void
    {
        self::assertTrue($this->filter('cualquier_cosa')->isAll());
        self::assertTrue($this->filter('')->matches(null));
        self::assertTrue($this->filter('')->matches($this->at('2020-01-01 00:00:00')));
    }

    public function testTodayMatchesOnlyReadingsInsideTheCurrentDay(): void
    {
        $filter = $this->filter(ReadingControlFilter::TODAY);

        self::assertTrue($filter->matches($this->at('2026-09-28 00:00:00')));
        self::assertTrue($filter->matches($this->at('2026-09-28 23:59:59')));
        self::assertFalse($filter->matches($this->at('2026-09-27 23:59:59')));
        self::assertFalse($filter->matches($this->at('2026-09-29 00:00:00')));
        self::assertFalse($filter->matches(null));
    }

    public function testNotTodayMatchesEverythingOutsideTheCurrentDay(): void
    {
        $filter = $this->filter(ReadingControlFilter::NOT_TODAY);

        self::assertFalse($filter->matches($this->at('2026-09-28 12:00:00')));
        self::assertTrue($filter->matches($this->at('2026-09-27 23:59:59')));
        self::assertTrue($filter->matches($this->at('2026-09-29 00:00:00')));
        self::assertTrue($filter->matches(null), 'Un equipo sin lectura pertenece a "sin cargar hoy".');
    }

    public function testGreaterThanThreeDaysExcludesRecentReadings(): void
    {
        $filter = $this->filter(ReadingControlFilter::GT_3);

        self::assertFalse($filter->matches($this->at('2026-09-28 09:00:00')));
        self::assertFalse($filter->matches($this->at('2026-09-27 10:00:00')), 'Con 1 día no supera los 3.');
        self::assertFalse($filter->matches($this->at('2026-09-25 10:00:00')), 'Con 3 días exactos no supera los 3.');
        self::assertTrue($filter->matches($this->at('2026-09-25 09:59:59')));
        self::assertTrue($filter->matches(null), 'Nunca leído es el peor caso.');
    }

    public function testGreaterThanSevenDaysIsStricterThanThreeDays(): void
    {
        $filter = $this->filter(ReadingControlFilter::GT_7);

        self::assertTrue($filter->matches($this->at('2026-09-20 10:00:00')), 'Con 8 días supera los 7.');
        self::assertFalse($filter->matches($this->at('2026-09-23 10:00:00')), 'Con 5 días no supera los 7.');
        self::assertTrue($filter->matches(null));
    }

    public function testDaysSinceIsNullWithoutReadingAndNeverNegative(): void
    {
        $filter = $this->filter(ReadingControlFilter::ALL);

        self::assertNull($filter->daysSince(null));
        self::assertSame(0, $filter->daysSince($this->at('2026-09-28 09:00:00')));
        self::assertSame(27, $filter->daysSince($this->at('2026-09-01 10:00:00')));
    }

    public function testCutoffsAreDerivedFromTheInjectedClock(): void
    {
        $filter = $this->filter(ReadingControlFilter::GT_7);

        self::assertSame('2026-09-21 10:00:00', $filter->cutoffFor(7)->format('Y-m-d H:i:s'));
        self::assertSame('2026-09-28 00:00:00', $filter->todayStart()->format('Y-m-d H:i:s'));
        self::assertSame('2026-09-29 00:00:00', $filter->tomorrowStart()->format('Y-m-d H:i:s'));
    }

    public function testSqlFilterKeysAndLabelsAreComplete(): void
    {
        $options = ReadingControlFilter::options();

        self::assertSame(ReadingControlFilter::KEYS, array_column($options, 'key'));
        self::assertSame(
            ['Todos', 'Cargaron hoy', 'Sin cargar hoy', 'Más de 3 días', 'Más de 7 días'],
            array_column($options, 'label'),
        );
    }
}
