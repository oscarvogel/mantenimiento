<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Measurement;

use App\Infrastructure\Measurement\MiniMaxOdometerImageAnalyzer;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class MiniMaxOdometerImageAnalyzerContractTest extends TestCase
{
    public function testPromptExplicitlyRejectsTripAndOtherDashboardValues(): void
    {
        $analyzer = new MiniMaxOdometerImageAnalyzer('dummy');
        $method = new ReflectionMethod($analyzer, 'prompt');
        $prompt = (string) $method->invoke($analyzer);

        self::assertStringContainsString('ODÓMETRO/KILOMETRAJE total acumulado', $prompt);
        self::assertStringContainsString('viaje parcial (trip)', $prompt);
        self::assertStringContainsString('"odometro":number|null', $prompt);
        self::assertStringContainsString('Trip/viaje parcial', $prompt);
        self::assertStringContainsString('1.000.000 km o más', $prompt);
        self::assertStringContainsString('497997.6', $prompt);
        self::assertStringContainsString('491138 km', $prompt);
        self::assertStringContainsString('1194076.9 km', $prompt);
        self::assertStringContainsString('No inventes dígitos', $prompt);
        self::assertStringContainsString('"evidencia_valida":boolean', $prompt);
        self::assertStringContainsString('NOT_DASHBOARD', $prompt);
        self::assertStringContainsString('ODOMETER_NOT_VISIBLE', $prompt);
        self::assertStringContainsString('TRIP_ONLY', $prompt);
        self::assertStringContainsString('TOO_BLURRY', $prompt);
        self::assertStringContainsString('foto sin tablero/odómetro no se acepta', $prompt);
    }
    public function testOdometerNormalizationCoversRealTruckFormats(): void
    {
        $analyzer = new MiniMaxOdometerImageAnalyzer('dummy');
        $method = new ReflectionMethod($analyzer, 'normalizeOdometerValue');

        self::assertSame(497998, $method->invoke($analyzer, 497997.6));
        self::assertSame(491138, $method->invoke($analyzer, 491138));
        self::assertSame(1194077, $method->invoke($analyzer, 1194076.9));
        self::assertSame(497998, $method->invoke($analyzer, '497.997,6'));
        self::assertSame(1194077, $method->invoke($analyzer, '1.194.076,9'));
        self::assertSame(491138, $method->invoke($analyzer, '491138'));
    }

}
