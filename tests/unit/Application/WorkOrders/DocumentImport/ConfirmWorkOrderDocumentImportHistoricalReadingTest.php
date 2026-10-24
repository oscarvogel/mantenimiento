<?php

declare(strict_types=1);

namespace Tests\Unit\Application\WorkOrders\DocumentImport;

use App\Application\WorkOrders\DocumentImport\ConfirmWorkOrderDocumentImport;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ConfirmWorkOrderDocumentImportHistoricalReadingTest extends TestCase
{
    public function testPastKilometresBelowCurrentAreHistorical(): void
    {
        self::assertTrue($this->isHistorical(
            new DateTimeImmutable('yesterday'),
            ['km_actual' => 717432, 'horas_actuales' => null],
            712889,
            null,
        ));
    }

    public function testPastHoursBelowCurrentAreHistorical(): void
    {
        self::assertTrue($this->isHistorical(
            new DateTimeImmutable('yesterday'),
            ['km_actual' => null, 'horas_actuales' => '1540.0'],
            null,
            '1512.5',
        ));
    }

    public function testCurrentDateRegressionIsNotHistorical(): void
    {
        self::assertFalse($this->isHistorical(
            new DateTimeImmutable('today'),
            ['km_actual' => 717432, 'horas_actuales' => null],
            712889,
            null,
        ));
    }

    public function testPastReadingAboveCurrentIsNotHistorical(): void
    {
        self::assertFalse($this->isHistorical(
            new DateTimeImmutable('yesterday'),
            ['km_actual' => 717432, 'horas_actuales' => null],
            718000,
            null,
        ));
    }

    /** @param array<string,mixed> $equipment */
    private function isHistorical(DateTimeImmutable $date, array $equipment, ?int $km, ?string $hours): bool
    {
        $reflection = new ReflectionClass(ConfirmWorkOrderDocumentImport::class);
        $instance = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('isHistoricalReading');
        $method->setAccessible(true);

        return (bool) $method->invoke($instance, $date, $equipment, $km, $hours);
    }
}
