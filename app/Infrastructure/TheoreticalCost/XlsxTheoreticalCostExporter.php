<?php

declare(strict_types=1);

namespace App\Infrastructure\TheoreticalCost;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Chart\Chart;
use PhpOffice\PhpSpreadsheet\Chart\DataSeries;
use PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues;
use PhpOffice\PhpSpreadsheet\Chart\Legend;
use PhpOffice\PhpSpreadsheet\Chart\PlotArea;
use PhpOffice\PhpSpreadsheet\Chart\Title;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

final class XlsxTheoreticalCostExporter
{
    private const NAVY = '031A3E';
    private const BLUE = '0877D1';
    private const GOLD = 'E5A100';
    private const LIGHT_BLUE = 'EAF3FC';
    private const LIGHT = 'F4F7FB';
    private const BORDER = 'D7E0EA';
    private const MUTED = '5D6B7C';
    private const RED = 'A72D2D';
    private const GREEN = '246B45';

    /** @param array<string,mixed> $scenario */
    public function export(array $scenario): string
    {
        $book = new Spreadsheet();
        $result = is_array($scenario['resultado'] ?? null) ? $scenario['resultado'] : [];
        $parameters = is_array($scenario['parametros'] ?? null) ? $scenario['parametros'] : [];
        $this->buildCover($book->getActiveSheet(), $scenario, $result, $parameters);
        $this->buildAssumptions($book->createSheet(), $parameters);
        $this->buildCostDetail($book->createSheet(), $result);
        $this->buildTrip($book, $result);

        $path = tempnam(sys_get_temp_dir(), 'theoretical-cost-');
        if ($path === false) {
            throw new RuntimeException('No se pudo preparar el archivo Excel.');
        }
        try {
            $writer = new Xlsx($book);
            $writer->setIncludeCharts(true);
            $writer->save($path);
            $contents = file_get_contents($path);
            if ($contents === false) {
                throw new RuntimeException('No se pudo leer el archivo Excel generado.');
            }
            return $contents;
        } finally {
            @unlink($path);
            $book->disconnectWorksheets();
        }
    }

    /** @param array<string,mixed> $scenario @param array<string,mixed> $result @param array<string,mixed> $parameters */
    private function buildCover(Worksheet $sheet, array $scenario, array $result, array $parameters): void
    {
        $sheet->setTitle('Carátula');
        $sheet->getParent()->getDefaultStyle()->getFont()->setName('Aptos')->setSize(10)->getColor()->setRGB(self::NAVY);
        $sheet->getColumnDimension('A')->setWidth(17);
        foreach (range('B', 'H') as $column) {
            $sheet->getColumnDimension($column)->setWidth(15);
        }

        $sheet->mergeCells('B1:H1')->setCellValue('B1', 'VOGEL CONSULTORÍA');
        $sheet->mergeCells('B2:H2')->setCellValue('B2', 'INFORME GERENCIAL · TRANSPORTE');
        $sheet->mergeCells('B4:H5')->setCellValue('B4', 'Costo teórico por kilómetro');
        foreach ([1 => 25, 2 => 21, 3 => 7, 4 => 27, 5 => 20, 6 => 7] as $row => $height) {
            $sheet->getRowDimension($row)->setRowHeight($height);
        }
        $sheet->getStyle('B1:H1')->applyFromArray([
            'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => self::NAVY]],
            'font' => ['bold' => true, 'size' => 16, 'color' => ['rgb' => 'FFFFFF']],
            'alignment' => ['vertical' => 'center'],
        ]);
        $sheet->getStyle('B2:H2')->applyFromArray([
            'font' => ['bold' => true, 'size' => 9, 'color' => ['rgb' => self::BLUE]],
            'alignment' => ['vertical' => 'center'],
        ]);
        $sheet->getStyle('B4:H5')->applyFromArray([
            'font' => ['bold' => true, 'size' => 22, 'color' => ['rgb' => self::NAVY]],
            'alignment' => ['vertical' => 'center'],
        ]);

        $rootPath = defined('ROOTPATH') ? rtrim(ROOTPATH, '/\\') : dirname(__DIR__, 3);
        $logoPath = $rootPath . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'brand' . DIRECTORY_SEPARATOR . 'vogel-consultoria.png';
        if (is_file($logoPath)) {
            $logo = new Drawing();
            $logo->setName('Vogel Consultoría');
            $logo->setDescription('Identidad visual de Vogel Consultoría');
            $logo->setPath($logoPath);
            $logo->setHeight(70);
            $logo->setCoordinates('A1');
            $logo->setOffsetX(8);
            $logo->setOffsetY(4);
            $logo->setWorksheet($sheet);
        }

        $scenarioName = trim((string) ($scenario['nombre'] ?? 'Escenario sin nombre'));
        $version = (int) ($scenario['version'] ?? 1);
        $sheet->mergeCells('A7:H7')->setCellValue('A7', sprintf('ESCENARIO  %s     ·     VERSIÓN %d', $scenarioName, $version));
        $sheet->getStyle('A7:H7')->applyFromArray([
            'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => self::LIGHT_BLUE]],
            'font' => ['bold' => true, 'color' => ['rgb' => self::NAVY]],
            'alignment' => ['vertical' => 'center'],
            'borders' => ['bottom' => ['borderStyle' => 'thin', 'color' => ['rgb' => self::BORDER]]],
        ]);
        $sheet->getRowDimension(7)->setRowHeight(25);

        $this->metricCard($sheet, 'A9:D9', 'A10:D12', 'Costo teórico por km · ARS', $result['costo_km']['ars'] ?? null, self::BLUE, '"$" #,##0.00');
        $this->metricCard($sheet, 'E9:H9', 'E10:H12', 'Costo teórico por km · USD', $result['costo_km']['usd'] ?? null, self::NAVY, '"US$" #,##0.00');
        $this->metricCard($sheet, 'A14:D14', 'A15:D17', 'Tarifa sugerida con recargo · ARS', $result['tarifa_km']['ars'] ?? null, self::NAVY, '"$" #,##0.00');
        $this->metricCard($sheet, 'E14:H14', 'E15:H17', 'Tarifa sugerida con recargo · USD', $result['tarifa_km']['usd'] ?? null, self::GOLD, '"US$" #,##0.00');
        foreach ([9 => 23, 10 => 22, 11 => 22, 12 => 22, 14 => 23, 15 => 22, 16 => 22, 17 => 22] as $row => $height) {
            $sheet->getRowDimension($row)->setRowHeight($height);
        }

        $missing = is_array($result['faltantes'] ?? null) ? array_values(array_filter($result['faltantes'], 'is_string')) : [];
        $coverage = (string) ($result['cobertura'] ?? 'parcial');
        $sheet->mergeCells('A19:H19')->setCellValue('A19', 'ESTADO DEL ANÁLISIS');
        $missingLabels = array_map(fn (string $code): string => $this->missingLabel($code), $missing);
        $sheet->mergeCells('A20:H21')->setCellValue('A20', $missing === []
            ? 'Cobertura completa. Revisá que los supuestos representen la operación que querés evaluar.'
            : 'FALTAN SUPUESTOS: ' . implode(', ', $missingLabels) . '. Completalos antes de usar la referencia para cotizar.');
        $statusColor = $missing === [] ? self::GREEN : self::RED;
        $statusFill = $missing === [] ? 'EAF5EE' : 'FFF1EF';
        $sheet->getStyle('A19:H19')->applyFromArray([
            'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => $statusColor]],
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 9],
        ]);
        $sheet->getStyle('A20:H21')->applyFromArray([
            'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => $statusFill]],
            'font' => ['bold' => true, 'color' => ['rgb' => $statusColor], 'size' => 11],
            'alignment' => ['wrapText' => true, 'vertical' => 'center'],
            'borders' => ['outline' => ['borderStyle' => 'thin', 'color' => ['rgb' => $statusColor]]],
        ]);
        $sheet->getRowDimension(19)->setRowHeight(21);
        $sheet->getRowDimension(20)->setRowHeight(27);
        $sheet->getRowDimension(21)->setRowHeight(27);

        $fx = is_array($result['cotizacion'] ?? null) ? $result['cotizacion'] : [];
        $tractor = is_array($scenario['tractor'] ?? null) ? $scenario['tractor'] : [];
        $semi = is_array($scenario['semi'] ?? null) ? $scenario['semi'] : [];
        $metadata = [
            ['Escenario', $scenarioName, 'Versión', $version],
            ['Conjunto', $this->equipmentLabel($tractor, 'Tractor') . ' / ' . $this->equipmentLabel($semi, 'Semi'), 'Guardado', $scenario['guardado_en'] ?? ''],
            ['Km operativos previstos', $parameters['km_mensuales'] ?? null, 'Recargo aplicado', $result['recargo_pct'] ?? null],
            ['Cotización BCRA/referencia', $fx['ars_por_usd'] ?? null, 'Fecha de cotización', $fx['fecha'] ?? ''],
            ['Fuente de cotización', $fx['fuente'] ?? '', 'Cobertura', $coverage],
        ];
        $sheet->mergeCells('A23:H23')->setCellValue('A23', 'DATOS DEL ESCENARIO');
        $sheet->getStyle('A23:H23')->applyFromArray([
            'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => self::NAVY]],
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
        ]);
        foreach ($metadata as $index => $row) {
            $targetRow = 24 + $index;
            $sheet->setCellValue('A' . $targetRow, $row[0]);
            $sheet->setCellValue('B' . $targetRow, $row[1]);
            $sheet->setCellValue('D' . $targetRow, $row[2]);
            $sheet->setCellValue('E' . $targetRow, $row[3]);
            $sheet->mergeCells('B' . $targetRow . ':C' . $targetRow);
            $sheet->mergeCells('E' . $targetRow . ':H' . $targetRow);
            $sheet->getStyle('A' . $targetRow . ':H' . $targetRow)->applyFromArray([
                'borders' => ['bottom' => ['borderStyle' => 'hair', 'color' => ['rgb' => self::BORDER]]],
                'alignment' => ['vertical' => 'center', 'wrapText' => true],
            ]);
            $sheet->getStyle('B' . $targetRow . ':C' . $targetRow)->getAlignment()->setHorizontal('left');
            $sheet->getStyle('E' . $targetRow . ':H' . $targetRow)->getAlignment()->setHorizontal('left');
            $sheet->getStyle('A' . $targetRow . ':A' . $targetRow)->getFont()->setBold(true)->getColor()->setRGB(self::MUTED);
            $sheet->getStyle('D' . $targetRow . ':D' . $targetRow)->getFont()->setBold(true)->getColor()->setRGB(self::MUTED);
            if ($index % 2 === 1) {
                $sheet->getStyle('A' . $targetRow . ':H' . $targetRow)->getFill()->setFillType('solid')->getStartColor()->setRGB(self::LIGHT);
            }
        }
        $sheet->getStyle('B26')->getNumberFormat()->setFormatCode('#,##0 "km/mes"');
        $sheet->getStyle('E26')->getNumberFormat()->setFormatCode('0.00"%"');
        $sheet->getStyle('B27')->getNumberFormat()->setFormatCode('"$" #,##0.00 "ARS/USD"');
        $sheet->mergeCells('A31:H32')->setCellValue('A31', 'Referencia de gestión basada en los supuestos guardados. No es una tarifa oficial ni incluye impuestos de venta. Confirmá precios, utilización y valores de los activos antes de cotizar.');
        $sheet->getStyle('A31:H32')->applyFromArray([
            'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => self::LIGHT_BLUE]],
            'font' => ['color' => ['rgb' => self::NAVY], 'italic' => true, 'size' => 9],
            'alignment' => ['wrapText' => true, 'vertical' => 'center'],
            'borders' => ['outline' => ['borderStyle' => 'thin', 'color' => ['rgb' => 'AFCBE8']]],
        ]);
        $sheet->getRowDimension(31)->setRowHeight(24);
        $sheet->getRowDimension(32)->setRowHeight(24);
        $this->configurePrint($sheet, true);
    }

    private function metricCard(Worksheet $sheet, string $labelRange, string $valueRange, string $label, mixed $value, string $accent, string $numberFormat): void
    {
        [$labelStart] = explode(':', $labelRange);
        [$valueStart] = explode(':', $valueRange);
        $sheet->mergeCells($labelRange)->setCellValue($labelStart, $label);
        $sheet->mergeCells($valueRange);
        if (is_numeric($value) && is_finite((float) $value)) {
            $sheet->setCellValue($valueStart, (float) $value);
            $sheet->getStyle($valueStart)->getNumberFormat()->setFormatCode($numberFormat);
        } else {
            $sheet->setCellValue($valueStart, 'Sin datos');
        }
        $sheet->getStyle($labelRange)->applyFromArray([
            'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => $accent]],
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 9],
            'alignment' => ['horizontal' => 'center', 'vertical' => 'center', 'wrapText' => true],
        ]);
        $sheet->getStyle($valueRange)->applyFromArray([
            'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => self::LIGHT]],
            'font' => ['bold' => true, 'size' => 20, 'color' => ['rgb' => $accent]],
            'alignment' => ['horizontal' => 'center', 'vertical' => 'center'],
            'borders' => ['outline' => ['borderStyle' => 'medium', 'color' => ['rgb' => $accent]]],
        ]);
    }

    /** @param array<string,mixed> $parameters */
    private function buildAssumptions(Worksheet $sheet, array $parameters): void
    {
        $sheet->setTitle('Supuestos');
        $rows = [['Grupo', 'Supuesto', 'Valor', 'Moneda o unidad', 'Fecha de referencia', 'Fuente']];
        $this->parameterRows($rows, 'Utilización', 'Kilómetros operativos mensuales', $parameters['km_mensuales'] ?? null, 'km/mes');
        $this->parameterRows($rows, 'Cotización', 'Pesos argentinos por dólar', $parameters['fx']['ars_por_usd'] ?? null, 'ARS/USD', $parameters['fx']['fecha'] ?? null, $parameters['fx']['fuente'] ?? null);
        foreach (['tractor' => 'Tractor', 'semi' => 'Semi'] as $key => $label) {
            $asset = is_array($parameters[$key] ?? null) ? $parameters[$key] : [];
            $this->parameterRows($rows, $label, 'Valor de reposición', $asset['valor_usd'] ?? null, 'USD');
            $this->parameterRows($rows, $label, 'Valor residual', $asset['residual_usd'] ?? null, 'USD');
            $this->parameterRows($rows, $label, 'Vida útil', $asset['vida_util_anios'] ?? null, 'años');
        }
        $fuel = is_array($parameters['combustible'] ?? null) ? $parameters['combustible'] : [];
        $this->parameterRows($rows, 'Combustible', 'Consumo', $fuel['litros_100km'] ?? null, 'litros/100 km');
        $this->parameterRows($rows, 'Combustible', 'Precio por litro', $fuel['precio'] ?? null, $fuel['moneda'] ?? '', $fuel['fecha'] ?? null, $fuel['fuente'] ?? null);
        $tires = is_array($parameters['neumaticos'] ?? null) ? $parameters['neumaticos'] : [];
        $this->parameterRows($rows, 'Neumáticos', 'Costo del juego', $tires['costo_juego'] ?? null, $tires['moneda'] ?? '');
        $this->parameterRows($rows, 'Neumáticos', 'Recapados previstos', $tires['recapados'] ?? null, 'unidades');
        $this->parameterRows($rows, 'Neumáticos', 'Vida útil total', $tires['vida_util_km'] ?? null, 'km');
        $this->parameterRows($rows, 'Mantenimiento', 'Mantenimiento y reparaciones', $parameters['mantenimiento_pct'] ?? null, '% del costo total');
        $this->parameterRows($rows, 'Tarifa', 'Recargo sobre costo', $parameters['recargo_pct'] ?? null, '%');

        foreach (['costos_mensuales' => 'Costo mensual', 'otros_rubros' => 'Otro rubro'] as $key => $group) {
            foreach (($parameters[$key] ?? []) as $line) {
                if (! is_array($line)) {
                    continue;
                }
                $unit = ($line['unidad'] ?? null) === 'porcentaje'
                    ? '%'
                    : trim((string) ($line['moneda'] ?? '') . '/' . (string) ($line['unidad'] ?? ''), '/');
                $rows[] = [$group, $line['nombre'] ?? $line['codigo'] ?? '', $line['importe'] ?? null, $unit, $line['fecha'] ?? '', $line['fuente'] ?? ''];
            }
        }

        $this->writeTable($sheet, $rows, ['A' => 20, 'B' => 35, 'C' => 17, 'D' => 23, 'E' => 20, 'F' => 30]);
        $sheet->getStyle('C2:C' . count($rows))->getNumberFormat()->setFormatCode('#,##0.00;[Red]-#,##0.00;–');
        $this->configurePrint($sheet);
    }

    /** @param array<string,mixed> $result */
    private function buildCostDetail(Worksheet $sheet, array $result): void
    {
        $sheet->setTitle('Detalle de costos');
        $rows = [['Código', 'Rubro', 'Unidad de cálculo', 'Importe de origen', 'Moneda de origen', 'ARS/km', 'USD/km', 'Estado']];
        foreach (($result['rubros'] ?? []) as $line) {
            if (! is_array($line)) {
                continue;
            }
            $rows[] = [
                $line['codigo'] ?? '', $line['nombre'] ?? '', $line['unidad'] ?? '',
                $line['importe_origen'] ?? null, $line['moneda_origen'] ?? '',
                $line['ars_km'] ?? null, $line['usd_km'] ?? null, $line['estado'] ?? 'faltante',
            ];
        }
        $this->writeTable($sheet, $rows, ['A' => 23, 'B' => 36, 'C' => 27, 'D' => 20, 'E' => 19, 'F' => 19, 'G' => 19, 'H' => 17]);
        $lastRow = count($rows);
        if ($lastRow > 1) {
            $sheet->getStyle('D2:D' . $lastRow)->getNumberFormat()->setFormatCode('#,##0.00;[Red]-#,##0.00;–');
            $sheet->getStyle('F2:G' . $lastRow)->getNumberFormat()->setFormatCode('#,##0.0000;[Red]-#,##0.0000;–');
            $hasConfiguredAmounts = false;
            for ($row = 2; $row <= $lastRow; $row++) {
                $state = strtolower((string) $sheet->getCell('H' . $row)->getValue());
                $color = $state === 'configurado' ? self::GREEN : self::RED;
                $sheet->getStyle('H' . $row)->getFont()->setBold(true)->getColor()->setRGB($color);
                $hasConfiguredAmounts = $hasConfiguredAmounts || ($state === 'configurado' && is_numeric($sheet->getCell('F' . $row)->getValue()));
                if ($state !== 'configurado') {
                    $sheet->getStyle('A' . $row . ':H' . $row)->getFill()->setFillType('solid')->getStartColor()->setRGB('FFF1EF');
                }
            }
            if ($hasConfiguredAmounts) {
                $this->addCostChart($sheet, $lastRow + 3, $lastRow);
            }
        }
        $this->configurePrint($sheet);
    }

    private function addCostChart(Worksheet $sheet, int $topRow, int $lastDataRow): void
    {
        $categories = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'Detalle de costos'!\$B\$2:\$B\$$lastDataRow", null, $lastDataRow - 1);
        $values = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, "'Detalle de costos'!\$F\$2:\$F\$$lastDataRow", '#,##0.00', $lastDataRow - 1, [], null, self::BLUE);
        $labels = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'Detalle de costos'!\$F\$1", null, 1)];
        $series = new DataSeries(
            DataSeries::TYPE_BARCHART,
            DataSeries::GROUPING_CLUSTERED,
            [0],
            $labels,
            [$categories],
            [$values],
            DataSeries::DIRECTION_BAR,
        );
        $chart = new Chart(
            'costoPorRubro',
            new Title('Costos configurados por rubro (ARS/km)'),
            new Legend(Legend::POSITION_RIGHT, null, false),
            new PlotArea(null, [$series]),
            true,
            DataSeries::EMPTY_AS_GAP,
            null,
            new Title('ARS/km'),
        );
        $chart->setTopLeftPosition('A' . $topRow);
        $chart->setBottomRightPosition('H' . ($topRow + 16));
        $sheet->addChart($chart);
    }

    /** @param array<string,mixed> $result */
    private function buildTrip(Spreadsheet $book, array $result): void
    {
        $trip = $result['viaje'] ?? null;
        if (! is_array($trip)) {
            return;
        }
        $sheet = $book->createSheet();
        $rows = [
            ['Simulación del viaje', 'Valor', 'Unidad'],
            ['Estado', $trip['estado'] ?? 'incompleto', ''],
            ['Cobertura', $trip['cobertura'] ?? $result['cobertura'] ?? 'parcial', ''],
            ['Kilómetros facturables', $trip['km_facturables'] ?? null, 'km'],
            ['Kilómetros no facturados', $trip['km_no_facturados'] ?? null, 'km'],
            ['Peajes', $trip['peajes'] ?? null, $trip['peajes_moneda'] ?? ''],
            ['Peajes normalizados', $trip['peajes_ars'] ?? null, 'ARS'],
            ['Costo estimado del viaje', $trip['costo_ars'] ?? null, 'ARS'],
            ['Tarifa sugerida por km facturable', $trip['tarifa_ars_km'] ?? null, 'ARS/km'],
            ['Tarifa sugerida por km facturable', $trip['tarifa_usd_km'] ?? null, 'USD/km'],
        ];
        $sheet->setTitle('Viaje');
        $this->writeTable($sheet, $rows, ['A' => 42, 'B' => 22, 'C' => 16]);
        $sheet->getStyle('B4:B10')->getNumberFormat()->setFormatCode('#,##0.00;[Red]-#,##0.00;–');
        $this->configurePrint($sheet);
    }

    /** @param list<array<int,mixed>> $rows @param array<string,int> $widths */
    private function writeTable(Worksheet $sheet, array $rows, array $widths): void
    {
        $sheet->getParent()->getDefaultStyle()->getFont()->setName('Aptos')->setSize(10)->getColor()->setRGB(self::NAVY);
        foreach ($rows as $rowIndex => $row) {
            foreach ($row as $columnIndex => $value) {
                $cell = $sheet->getCell([$columnIndex + 1, $rowIndex + 1]);
                if (is_int($value) || is_float($value)) {
                    $cell->setValue($value);
                } elseif (is_string($value) && preg_match('/^-?\d+(?:\.\d+)?$/', $value) === 1) {
                    $cell->setValue((float) $value);
                } else {
                    $cell->setValueExplicit($value === null ? '' : (string) $value, DataType::TYPE_STRING);
                }
            }
        }
        $lastColumn = array_key_last($widths);
        $lastRow = count($rows);
        $sheet->getStyle('A1:' . $lastColumn . '1')->applyFromArray([
            'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => self::NAVY]],
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 10],
            'alignment' => ['vertical' => 'center', 'wrapText' => true],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(30);
        if ($lastRow > 1) {
            $sheet->getStyle('A2:' . $lastColumn . $lastRow)->applyFromArray([
                'borders' => ['bottom' => ['borderStyle' => 'hair', 'color' => ['rgb' => self::BORDER]]],
                'alignment' => ['vertical' => 'center', 'wrapText' => true],
            ]);
            for ($row = 2; $row <= $lastRow; $row += 2) {
                $sheet->getStyle('A' . $row . ':' . $lastColumn . $row)->getFill()->setFillType('solid')->getStartColor()->setRGB(self::LIGHT);
            }
        }
        foreach ($widths as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
        $sheet->setAutoFilter('A1:' . $lastColumn . $lastRow);
        $sheet->freezePane('A2');
    }

    private function configurePrint(Worksheet $sheet, bool $singlePage = false): void
    {
        $sheet->getPageSetup()->setPaperSize(PageSetup::PAPERSIZE_A4)
            ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
            ->setFitToWidth(1)
            ->setFitToHeight($singlePage ? 1 : 0);
        $sheet->getPageSetup()->setFitToPage(true);
        $sheet->getPageMargins()->setTop(0.35)->setBottom(0.35)->setLeft(0.35)->setRight(0.35);
        $sheet->getHeaderFooter()->setOddFooter('&LVogel Consultoría&R&P / &N');
        if (! $singlePage) {
            $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(1, 1);
        }
    }

    /** @param array<string,mixed> $asset */
    private function equipmentLabel(array $asset, string $label): string
    {
        if ($asset === []) {
            return $label . ' no asociado';
        }
        return trim((string) ($asset['codigo'] ?? '') . ' ' . (string) ($asset['tipo'] ?? '')) ?: $label . ' asociado';
    }

    private function missingLabel(string $code): string
    {
        $labels = [
            'cotizacion_usd' => 'cotización ARS por USD',
            'km_mensuales' => 'kilómetros operativos mensuales',
            'tractor' => 'datos de depreciación del tractor',
            'semi' => 'datos de depreciación del semi',
            'combustible' => 'consumo y precio del gasoil',
            'neumaticos' => 'costo y vida útil de neumáticos',
            'lubricantes' => 'porcentaje de lubricantes',
            'costo_financiero' => 'porcentaje de costo financiero',
            'mantenimiento_pct' => 'porcentaje de mantenimiento',
            'recargo_pct' => 'porcentaje de recargo',
        ];
        return $labels[$code] ?? ucfirst(str_replace(['_', '.'], ' ', $code));
    }

    /** @param list<array<int,mixed>> $rows */
    private function parameterRows(array &$rows, string $group, string $name, mixed $value, string $unit, mixed $date = '', mixed $source = ''): void
    {
        $rows[] = [$group, $name, $value, $unit, $date ?? '', $source ?? ''];
    }
}
