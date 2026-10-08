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
        self::assertStringContainsString('"odometro":integer|null', $prompt);
        self::assertStringContainsString('No inventes dígitos', $prompt);
    }
}
