<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\TheoreticalCost;

use App\Infrastructure\TheoreticalCost\XlsxTheoreticalCostExporter;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\TestCase;

final class XlsxTheoreticalCostExporterTest extends TestCase
{
    public function testExportsABrandedCoverWithCostAndSuggestedTariffInArsAndUsd(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'theoretical-cost-test-');
        self::assertNotFalse($path);

        try {
            file_put_contents($path, (new XlsxTheoreticalCostExporter())->export($this->scenario()));
            $reader = IOFactory::createReaderForFile($path);
            $reader->setIncludeCharts(true);
            $book = $reader->load($path);

            self::assertSame('Carátula', $book->getSheet(0)->getTitle());
            $cover = $book->getSheet(0);
            self::assertSame('VOGEL CONSULTORÍA', $cover->getCell('B1')->getValue());
            self::assertSame(1234.56, $cover->getCell('A10')->getValue());
            self::assertSame(0.82, $cover->getCell('E10')->getValue());
            self::assertSame(1420.74, $cover->getCell('A15')->getValue());
            self::assertSame(0.95, $cover->getCell('E15')->getValue());
            self::assertCount(1, $cover->getDrawingCollection());
            self::assertGreaterThan(0, $book->getSheetByName('Detalle de costos')->getChartCount());
        } finally {
            @unlink($path);
        }
    }

    /** @return array<string,mixed> */
    private function scenario(): array
    {
        return [
            'nombre' => 'Prueba norte',
            'version' => 1,
            'guardado_en' => '2026-10-12 10:30:00',
            'tractor' => [],
            'semi' => [],
            'parametros' => [
                'km_mensuales' => 10000,
                'fx' => ['ars_por_usd' => 1500, 'fecha' => '2026-10-12', 'fuente' => 'BCRA'],
            ],
            'resultado' => [
                'cobertura' => 'completa',
                'faltantes' => [],
                'costo_km' => ['ars' => 1234.56, 'usd' => 0.82],
                'tarifa_km' => ['ars' => 1420.74, 'usd' => 0.95],
                'recargo_pct' => 15,
                'cotizacion' => ['ars_por_usd' => 1500, 'fecha' => '2026-10-12', 'fuente' => 'BCRA'],
                'rubros' => [
                    ['codigo' => 'combustible', 'nombre' => 'Gasoil', 'unidad' => 'por_km', 'importe_origen' => 37, 'moneda_origen' => 'ARS', 'ars_km' => 800, 'usd_km' => 0.533333, 'estado' => 'configurado'],
                    ['codigo' => 'neumaticos', 'nombre' => 'Neumáticos y recapados', 'unidad' => 'por_km', 'importe_origen' => 250, 'moneda_origen' => 'ARS', 'ars_km' => 250, 'usd_km' => 0.166667, 'estado' => 'configurado'],
                    ['codigo' => 'mantenimiento', 'nombre' => 'Mantenimiento y reparaciones', 'unidad' => 'por_km', 'importe_origen' => 10, 'moneda_origen' => '%', 'ars_km' => 100, 'usd_km' => 0.066667, 'estado' => 'configurado'],
                    ['codigo' => 'costo_financiero', 'nombre' => 'Costo financiero', 'unidad' => 'porcentaje_costo_total', 'importe_origen' => 5, 'moneda_origen' => '%', 'ars_km' => 84.56, 'usd_km' => 0.056373, 'estado' => 'configurado'],
                ],
            ],
        ];
    }
}
