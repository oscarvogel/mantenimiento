<?php

declare(strict_types=1);

namespace Tests\Unit\Application\WorkOrders\DocumentImport;

use App\Application\WorkOrders\DocumentImport\ConfirmWorkOrderDocumentImport;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ConfirmWorkOrderDocumentImportHistoricalReadingTest extends TestCase
{
    public function testPastCorrectiveKilometresBelowCurrentAreHistorical(): void
    {
        self::assertTrue($this->isHistorical(
            'corrective',
            new DateTimeImmutable('yesterday'),
            ['km_actual' => 717432, 'horas_actuales' => null],
            712889,
            null,
        ));
    }

    public function testPastCorrectiveHoursBelowCurrentAreHistorical(): void
    {
        self::assertTrue($this->isHistorical(
            'corrective',
            new DateTimeImmutable('yesterday'),
            ['km_actual' => null, 'horas_actuales' => '1540.0'],
            null,
            '1512.5',
        ));
    }

    public function testCurrentDateRegressionIsNotHistorical(): void
    {
        self::assertFalse($this->isHistorical(
            'corrective',
            new DateTimeImmutable('today'),
            ['km_actual' => 717432, 'horas_actuales' => null],
            712889,
            null,
        ));
    }

    public function testPreventiveRegressionKeepsNormalRollbackProtection(): void
    {
        self::assertFalse($this->isHistorical(
            'preventive',
            new DateTimeImmutable('yesterday'),
            ['km_actual' => 717432, 'horas_actuales' => null],
            712889,
            null,
        ));
    }

    /** @param array<string,mixed> $equipment */
    private function isHistorical(string $action, DateTimeImmutable $date, array $equipment, ?int $km, ?string $hours): bool
    {
        $reflection = new ReflectionClass(ConfirmWorkOrderDocumentImport::class);
        $instance = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('isHistoricalCorrectiveReading');
        $method->setAccessible(true);

        return (bool) $method->invoke($instance, $action, $date, $equipment, $km, $hours);
    }
}
