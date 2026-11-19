<?php

declare(strict_types=1);

use App\Domain\Reports\TelemetryReadingReliability;
use PHPUnit\Framework\TestCase;

final class TelemetryReadingReliabilityTest extends TestCase
{
    private const EQUIPMENT = [
        ['id' => 10, 'code' => 'CAM-01', 'plate' => 'AB123CD', 'branchName' => 'Central'],
    ];

    public function testComparesIncrementsAndIgnoresTheAbsoluteOdometerOffset(): void
    {
        $readings = $this->readings([1000, 1500, 2000, 2500, 3000, 3500, 4000]);
        $samples = $this->samples([501000, 501500, 502000, 502500, 503000, 503500, 504000]);

        $result = (new TelemetryReadingReliability())->calculate(self::EQUIPMENT, $readings, $samples);

        self::assertSame(6, $result['summary']['intervalsComparable']);
        self::assertSame(6, $result['summary']['intervalsWithin']);
        self::assertSame(100.0, $result['summary']['withinPercent']);
        self::assertSame(0.0, $result['summary']['medianAbsoluteErrorKm']);
        self::assertSame(TelemetryReadingReliability::CONFIABLE, $result['equipment'][0]['verdict']);
        self::assertSame(3000, $result['summary']['driverKm']);
        self::assertSame(3000, $result['summary']['telemetryKm']);
    }

    public function testReportsDiscrepanciesBeyondTolerance(): void
    {
        $readings = $this->readings([1000, 1500, 2000, 2500, 3000, 3500, 4000]);
        $samples = $this->samples([10000, 10550, 11100, 11650, 12200, 12750, 13050]);

        $result = (new TelemetryReadingReliability())->calculate(self::EQUIPMENT, $readings, $samples);

        self::assertSame(6, $result['summary']['intervalsComparable']);
        self::assertSame(5, $result['summary']['intervalsWithin']);
        self::assertSame(83.3, $result['summary']['withinPercent']);
        self::assertSame(50.0, $result['summary']['medianAbsoluteErrorKm']);
        self::assertSame(8.3, $result['summary']['meanSignedErrorKm']);
        self::assertSame(TelemetryReadingReliability::REVISAR, $result['equipment'][0]['verdict']);
    }

    public function testAMissingSampleAtOneEndIsCoverageNotError(): void
    {
        $readings = $this->readings([1000, 1500, 2000]);
        $samples = $this->samples([5000, 5500, 6000, 6500]);

        $result = (new TelemetryReadingReliability())->calculate(self::EQUIPMENT, $readings, array_slice($samples, 0, 2));

        self::assertSame(2, $result['equipment'][0]['intervalsConsidered']);
        self::assertSame(1, $result['equipment'][0]['intervalsComparable']);
        self::assertSame(50.0, $result['equipment'][0]['coveragePercent']);
        self::assertSame(TelemetryReadingReliability::MUESTRA_INSUFICIENTE, $result['equipment'][0]['verdict']);
    }

    public function testWithoutReadingsOrSamplesReportsSinDatos(): void
    {
        $result = (new TelemetryReadingReliability())->calculate(self::EQUIPMENT, [], []);

        self::assertSame([], $result['equipment']);
        self::assertSame(0, $result['summary']['equipmentWithData']);
    }

    public function testReadingsWithoutAnyTelemetrySampleReportSinCobertura(): void
    {
        $readings = $this->readings([1000, 1500, 2000]);

        $result = (new TelemetryReadingReliability())->calculate(self::EQUIPMENT, $readings, []);

        self::assertSame(TelemetryReadingReliability::SIN_COBERTURA, $result['equipment'][0]['verdict']);
        self::assertSame('Sin telemetría', $result['equipment'][0]['integrationName']);
    }

    /** @param list<int> $kilometers @return list<array{equipmentId:int,at:DateTimeImmutable,km:int}> */
    private function readings(array $kilometers): array
    {
        return array_map(fn (int $value, int $index): array => [
            'equipmentId' => 10,
            'at' => new DateTimeImmutable(sprintf('2026-08-%02d 08:00:00', $index + 1)),
            'km' => $value,
        ], $kilometers, array_keys($kilometers));
    }

    /** @param list<int> $kilometers @return list<array{equipmentId:int,integrationId:int,integrationName:string,provider:string,at:DateTimeImmutable,km:int}> */
    private function samples(array $kilometers): array
    {
        return array_map(fn (int $value, int $index): array => [
            'equipmentId' => 10,
            'integrationId' => 31,
            'integrationName' => 'Obersat',
            'provider' => 'wialon',
            'at' => new DateTimeImmutable(sprintf('2026-08-%02d 08:05:00', $index + 1)),
            'km' => $value,
        ], $kilometers, array_keys($kilometers));
    }
}
