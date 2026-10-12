<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\TheoreticalCost;

use App\Domain\TheoreticalCost\TheoreticalCostCalculator;
use PHPUnit\Framework\TestCase;

final class TheoreticalCostCalculatorTest extends TestCase
{
    public function testShowsMonthlyItemsAsPendingKilometersWhenUsageIsMissing(): void
    {
        $result = (new TheoreticalCostCalculator())->calculate([
            'fx' => ['ars_por_usd' => 1500, 'fecha' => '2026-10-09', 'fuente' => 'BCRA A3500'],
            'tractor' => ['valor_usd' => 350000, 'residual_usd' => 90000, 'vida_util_anios' => 5],
            'semi' => ['valor_usd' => 150000, 'residual_usd' => 30000, 'vida_util_anios' => 5],
            'costos_mensuales' => [['codigo' => 'seguro', 'nombre' => 'Seguro', 'importe' => 15000, 'moneda' => 'ARS']],
        ]);

        self::assertContains('km_mensuales', $result['faltantes']);
        self::assertSame('pendiente_km_mensuales', $this->rubric($result, 'tractor')['estado']);
        self::assertEqualsWithDelta(4333.333333, $this->rubric($result, 'tractor')['importe_origen'], 0.001);
        self::assertSame('pendiente_km_mensuales', $this->rubric($result, 'semi')['estado']);
        self::assertSame('pendiente_km_mensuales', $this->rubric($result, 'seguro')['estado']);
    }

    public function testCalculatesGasoilPerKmUsingLitersPerHundredKilometers(): void
    {
        $result = (new TheoreticalCostCalculator())->calculate([
            'km_mensuales' => 10000,
            'fx' => ['ars_por_usd' => 1520, 'fecha' => '2026-10-12', 'fuente' => 'BCRA A3500'],
            'combustible' => ['litros_100km' => 37.04, 'precio' => 2340, 'moneda' => 'ARS', 'fecha' => '2026-10-12'],
        ]);

        self::assertEqualsWithDelta(866.736, $this->rubric($result, 'combustible')['ars_km'], 0.001);
    }

    /** @param array<string,mixed> $result @return array<string,mixed> */
    private function rubric(array $result, string $code): array
    {
        foreach ($result['rubros'] as $line) {
            if ($line['codigo'] === $code) return $line;
        }
        self::fail('No se encontró el rubro ' . $code);
    }
}
