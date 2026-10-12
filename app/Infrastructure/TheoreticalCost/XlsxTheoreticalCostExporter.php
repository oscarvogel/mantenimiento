<?php

declare(strict_types=1);

namespace App\Infrastructure\TheoreticalCost;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

final class XlsxTheoreticalCostExporter
{
    /** @param array<string,mixed> $scenario */
    public function export(array $scenario): string
    {
        $book = new Spreadsheet();
        $summary = $book->getActiveSheet();
        $summary->setTitle('Resumen');
        $result = $scenario['resultado'] ?? [];
        $fx = $result['cotizacion'] ?? [];
        $tractor = $scenario['tractor'] ?? [];
        $semi = $scenario['semi'] ?? [];
        $this->rows($summary, [
            ['Costo teórico por km', 'Escenario', $scenario['nombre'] ?? ''],
            ['', 'Versión', $scenario['version'] ?? ''],
            ['', 'Guardado', $scenario['guardado_en'] ?? ''],
            ['', 'Tractor', trim((string) ($tractor['codigo'] ?? '') . ' ' . (string) ($tractor['tipo'] ?? ''))],
            ['', 'Semi', trim((string) ($semi['codigo'] ?? '') . ' ' . (string) ($semi['tipo'] ?? ''))],
            ['', 'Cobertura', $result['cobertura'] ?? 'parcial'],
            ['', 'Faltantes', implode(', ', $result['faltantes'] ?? [])],
            ['', 'Cotización ARS por USD', $fx['ars_por_usd'] ?? null],
            ['', 'Fecha cotización', $fx['fecha'] ?? ''],
            ['', 'Fuente cotización', $fx['fuente'] ?? ''],
            ['', 'Costo teórico ARS/km', $result['costo_km']['ars'] ?? null],
            ['', 'Costo teórico USD/km', $result['costo_km']['usd'] ?? null],
            ['', 'Recargo (%)', $result['recargo_pct'] ?? null],
            ['', 'Tarifa de referencia ARS/km', $result['tarifa_km']['ars'] ?? null],
            ['', 'Tarifa de referencia USD/km', $result['tarifa_km']['usd'] ?? null],
        ]);

        $parameters = $book->createSheet();
        $parameters->setTitle('Parámetros');
        $parameterRows = [['Grupo', 'Parámetro', 'Valor', 'Moneda/unidad', 'Fecha', 'Fuente']];
        $params = $scenario['parametros'] ?? [];
        $this->parameterRows($parameterRows, 'Utilización', 'Kilómetros operativos mensuales', $params['km_mensuales'] ?? null, 'km/mes');
        $this->parameterRows($parameterRows, 'Cotización', 'ARS por USD', $params['fx']['ars_por_usd'] ?? null, 'ARS/USD', $params['fx']['fecha'] ?? null, $params['fx']['fuente'] ?? null);
        foreach (['tractor' => 'Tractor', 'semi' => 'Semi'] as $key => $label) {
            $asset = $params[$key] ?? [];
            $this->parameterRows($parameterRows, $label, 'Valor de reposición', $asset['valor_usd'] ?? null, 'USD');
            $this->parameterRows($parameterRows, $label, 'Valor residual', $asset['residual_usd'] ?? null, 'USD');
            $this->parameterRows($parameterRows, $label, 'Vida útil', $asset['vida_util_anios'] ?? null, 'años');
        }
        $fuel = $params['combustible'] ?? [];
        $this->parameterRows($parameterRows, 'Combustible', 'Consumo', $fuel['litros_100km'] ?? null, 'litros/100 km');
        $this->parameterRows($parameterRows, 'Combustible', 'Precio por litro', $fuel['precio'] ?? null, $fuel['moneda'] ?? '', $fuel['fecha'] ?? null);
        $tires = $params['neumaticos'] ?? [];
        $this->parameterRows($parameterRows, 'Neumáticos', 'Costo del juego', $tires['costo_juego'] ?? null, $tires['moneda'] ?? '');
        $this->parameterRows($parameterRows, 'Neumáticos', 'Recapados previstos', $tires['recapados'] ?? null, $tires['moneda'] ?? '');
        $this->parameterRows($parameterRows, 'Neumáticos', 'Vida útil', $tires['vida_util_km'] ?? null, 'km');
        $this->parameterRows($parameterRows, 'Mantenimiento', 'Porcentaje', $params['mantenimiento_pct'] ?? null, '%');
        foreach (['costos_mensuales' => 'Costo mensual', 'otros_rubros' => 'Otro rubro'] as $key => $label) {
            foreach (($params[$key] ?? []) as $line) {
                if (! is_array($line)) {
                    continue;
                }
                $parameterRows[] = [$label, $line['nombre'] ?? $line['codigo'] ?? '', $line['importe'] ?? null, ($line['moneda'] ?? '') . '/' . ($line['unidad'] ?? ''), $line['fecha'] ?? '', $line['fuente'] ?? ''];
            }
        }
        $this->rows($parameters, $parameterRows);

        $breakdown = $book->createSheet();
        $breakdown->setTitle('Detalle de costos');
        $detailRows = [['Código', 'Rubro', 'Unidad', 'Importe de origen', 'Moneda de origen', 'ARS/km', 'USD/km', 'Estado']];
        foreach (($result['rubros'] ?? []) as $line) {
            $detailRows[] = [$line['codigo'] ?? '', $line['nombre'] ?? '', $line['unidad'] ?? '', $line['importe_origen'] ?? null, $line['moneda_origen'] ?? '', $line['ars_km'] ?? null, $line['usd_km'] ?? null, $line['estado'] ?? 'faltante'];
        }
        $this->rows($breakdown, $detailRows);

        $tripSheet = $book->createSheet();
        $tripSheet->setTitle('Viaje');
        $trip = $result['viaje'] ?? null;
        if (is_array($trip)) {
            $this->rows($tripSheet, [
                ['Simulación de viaje', 'Valor', 'Unidad'],
                ['Estado', $trip['estado'] ?? 'incompleto', ''],
                ['Cobertura', $trip['cobertura'] ?? $result['cobertura'] ?? 'parcial', ''],
                ['Kilómetros facturables', $trip['km_facturables'] ?? null, 'km'],
                ['Kilómetros no facturados', $trip['km_no_facturados'] ?? null, 'km'],
                ['Peajes', $trip['peajes'] ?? null, $trip['peajes_moneda'] ?? ''],
                ['Peajes normalizados', $trip['peajes_ars'] ?? null, 'ARS'],
                ['Costo estimado del viaje', $trip['costo_ars'] ?? null, 'ARS'],
                ['Tarifa sugerida por km facturable', $trip['tarifa_ars_km'] ?? null, 'ARS/km'],
                ['Tarifa sugerida por km facturable', $trip['tarifa_usd_km'] ?? null, 'USD/km'],
            ]);
        } else {
            $this->rows($tripSheet, [['Simulación de viaje', 'No se guardó una simulación para esta versión.']]);
        }

        foreach ($book->getAllSheets() as $sheet) {
            $sheet->getStyle('1:1')->getFont()->setBold(true);
            foreach (range('A', $sheet->getHighestColumn()) as $column) {
                $sheet->getColumnDimension($column)->setAutoSize(true);
            }
            $sheet->freezePane('A2');
        }

        $path = tempnam(sys_get_temp_dir(), 'theoretical-cost-');
        if ($path === false) {
            throw new RuntimeException('No se pudo preparar el archivo Excel.');
        }
        try {
            (new Xlsx($book))->save($path);
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

    private function rows(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, array $rows): void
    {
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
    }

    private function parameterRows(array &$rows, string $group, string $name, mixed $value, string $unit, mixed $date = '', mixed $source = ''): void
    {
        $rows[] = [$group, $name, $value, $unit, $date ?? '', $source ?? ''];
    }
}
