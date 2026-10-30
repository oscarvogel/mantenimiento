<?php

declare(strict_types=1);

namespace App\Infrastructure\Trips;

use App\Application\Trips\Port\OperationalTripSummaryPdfRenderer;
use FPDF;

final class OperationalTripSummaryPdfGenerator implements OperationalTripSummaryPdfRenderer
{
    public function render(array $trip): string
    {
        $pdf = new FPDF('P', 'mm', 'A4');
        $pdf->SetTitle($this->pdfText('Resumen del viaje #' . ($trip['trip']['numero'] ?? '')));
        $pdf->SetAuthor('Mantenimiento');
        $pdf->SetMargins(10, 10, 10);
        $pdf->SetAutoPageBreak(true, 12);
        $pdf->SetCompression(false);
        $pdf->AddPage();

        $this->title($pdf, 'Resumen del viaje #' . ($trip['trip']['numero'] ?? ''));
        $this->identity($pdf, $trip);
        $this->legs($pdf, $trip['legs'] ?? []);
        $this->fuel($pdf, $trip['fuel'] ?? []);
        $this->expenses($pdf, $trip['expenses'] ?? []);

        $pdf->AddPage();
        $this->title($pdf, 'Liquidación y rendimiento');
        $this->cash($pdf, $trip['cashMovements'] ?? []);
        $this->otherEarnings($pdf, $trip['otherEarnings'] ?? []);
        $this->settlement($pdf, $trip['settlement'] ?? null);
        $this->profitability($pdf, $trip['totals'] ?? [], $trip['settlement'] ?? null);

        return $pdf->Output('S');
    }

    /** @param array<string,mixed> $trip */
    private function identity(FPDF $pdf, array $trip): void
    {
        $record = $trip['trip'] ?? [];
        $assignments = $trip['assignments'] ?? [];
        $tractor = $this->assignment($assignments, 'TRACTOR');
        $trailer = $this->assignment($assignments, 'ACOPLADO');
        $driver = $this->assignment($assignments, 'CHOFER');
        $kmStart = $record['km_salida'] ?? null;
        $kmEnd = $record['km_regreso'] ?? null;

        $this->textLine($pdf, 'Empresa: ' . ($trip['company']['name'] ?? 'Sin dato') . '    Sucursal: ' . ($record['sucursal'] ?? 'Sin dato'));
        $this->textLine($pdf, 'Unidad: ' . ($tractor ?: 'Sin dato') . ($trailer ? '    Acoplado: ' . $trailer : ''));
        $driverLine = 'Chofer: ' . ($driver ?: 'Sin dato');
        $driverAssignment = $this->assignmentRow($assignments, 'CHOFER');
        if (($driverAssignment['porcentaje_liquidacion'] ?? null) !== null) {
            $driverLine .= '    Comisión: ' . $this->number($driverAssignment['porcentaje_liquidacion']) . '%';
        }
        $this->textLine($pdf, $driverLine);
        $this->textLine($pdf, 'Salida: ' . $this->date($record['fecha_salida_real'] ?? null) . '    Regreso: ' . $this->date($record['fecha_regreso_real'] ?? null));
        $distance = $trip['consumption']['km'] ?? null;
        $this->textLine($pdf, 'Km salida: ' . $this->numberOrNoData($kmStart) . '    Km regreso: ' . $this->numberOrNoData($kmEnd)
            . '    Km recorridos: ' . $this->numberOrNoData($distance)
            . '    Litros: ' . $this->number($trip['totals']['fuel_liters'] ?? 0)
            . '    Consumo: ' . (($trip['consumption']['liters_per_100'] ?? null) === null
                ? 'Sin dato' : $this->number($trip['consumption']['liters_per_100'])) . ' L/100 km');
        if (trim((string) ($record['observaciones'] ?? '')) !== '') {
            $this->textLine($pdf, 'Observaciones: ' . (string) $record['observaciones']);
        }
        $pdf->Ln(2);
    }

    /** @param list<array<string,mixed>> $rows */
    private function legs(FPDF $pdf, array $rows): void
    {
        $this->section($pdf, 'Fletes por viaje');
        $widths = [8, 80, 18, 14, 35, 35];
        $this->tableHeader($pdf, $widths, ['#', 'Código · origen / destino · cliente', 'Km', 'Tns', 'Facturación', 'Liquidación']);
        if ($rows === []) {
            $this->emptyRow($pdf, 'No hay tramos cargados.');
            return;
        }
        foreach ($rows as $index => $row) {
            $route = trim((string) ($row['origen'] ?? '') . ' -> ' . (string) ($row['destino'] ?? ''));
            if (! empty($row['codigo_origen_destino'])) {
                $route = (string) $row['codigo_origen_destino'] . ' · ' . $route;
            }
            if (trim((string) ($row['cliente'] ?? '')) !== '') {
                $customerCode = trim((string) ($row['cliente_codigo'] ?? ''));
                $route .= ' · ' . ($customerCode !== '' ? $customerCode . ' ' : '') . $row['cliente'];
            }
            $this->tableRow($pdf, $widths, [
                (string) ($row['orden'] ?? ($index + 1)), $route,
                $this->numberOrNoData($row['km'] ?? null),
                $this->numberOrNoData($row['toneladas'] ?? null),
                $this->money($row['flete_facturacion_ars'] ?? 0),
                $this->money($row['flete_liquidacion_ars'] ?? 0),
            ], ['', 'L', 'R', 'R', 'R', 'R']);
        }
        $pdf->Ln(3);
    }

    /** @param list<array<string,mixed>> $rows */
    private function fuel(FPDF $pdf, array $rows): void
    {
        $this->section($pdf, 'Cargas de combustible');
        $widths = [27, 20, 25, 70, 48];
        $this->tableHeader($pdf, $widths, ['Fecha', 'Litros', 'Odómetro', 'Estación / comprobante', 'Importe ARS']);
        if ($rows === []) {
            $this->emptyRow($pdf, 'No hay cargas de combustible.');
            return;
        }
        foreach ($rows as $row) {
            $station = trim((string) ($row['estacion'] ?? ''));
            if (trim((string) ($row['comprobante'] ?? '')) !== '') {
                $station .= ' · ' . $row['comprobante'];
            }
            $this->tableRow($pdf, $widths, [
                $this->date($row['fecha'] ?? null), $this->number($row['litros'] ?? 0),
                $this->numberOrNoData($row['kilometraje'] ?? null), $station ?: 'Sin dato',
                $this->money($row['importe_ars'] ?? 0),
            ], ['L', 'R', 'R', 'L', 'R']);
        }
        $pdf->Ln(3);
    }

    /** @param list<array<string,mixed>> $rows */
    private function expenses(FPDF $pdf, array $rows): void
    {
        $this->section($pdf, 'Otros gastos');
        $widths = [27, 65, 28, 32, 38];
        $this->tableHeader($pdf, $widths, ['Fecha', 'Categoría / detalle', 'Pagado por', 'Comprobante', 'Importe ARS']);
        if ($rows === []) {
            $this->emptyRow($pdf, 'No hay gastos cargados.');
            return;
        }
        foreach ($rows as $row) {
            $detail = trim((string) ($row['categoria'] ?? ''));
            if (trim((string) ($row['observaciones'] ?? '')) !== '') {
                $detail .= ' · ' . $row['observaciones'];
            }
            $this->tableRow($pdf, $widths, [
                $this->date($row['fecha'] ?? null), $detail ?: 'Sin categoría',
                $this->paidBy((string) ($row['pagado_por'] ?? 'EMPRESA')),
                (string) ($row['comprobante'] ?? ''), $this->money($row['importe_ars'] ?? 0),
            ], ['L', 'L', 'L', 'L', 'R']);
        }
        $pdf->Ln(3);
    }

    /** @param list<array<string,mixed>> $rows */
    private function cash(FPDF $pdf, array $rows): void
    {
        $this->section($pdf, 'Cuenta corriente del chofer');
        $widths = [24, 24, 48, 48, 46];
        $this->tableHeader($pdf, $widths, ['Fecha', 'Movimiento', 'Importe / cotización', 'Observaciones', 'Equivalente ARS']);
        if ($rows === []) {
            $this->emptyRow($pdf, 'No hay movimientos de caja.');
            return;
        }
        foreach ($rows as $row) {
            $currency = strtoupper((string) ($row['moneda'] ?? 'ARS'));
            $amount = $currency . ' ' . $this->number($row['importe'] ?? 0);
            $rate = (float) ($row['cotizacion'] ?? 1);
            if ($currency !== 'ARS' || $rate !== 1.0) {
                $amount .= ' x ' . $this->number($rate);
            }
            $this->tableRow($pdf, $widths, [
                $this->date($row['fecha'] ?? null), $this->cashType((string) ($row['tipo'] ?? '')),
                $amount, (string) ($row['observaciones'] ?? ''),
                $this->money($row['importe_ars'] ?? 0),
            ], ['L', 'L', 'L', 'L', 'R']);
        }
        $pdf->Ln(3);
    }

    /** @param list<array<string,mixed>> $rows */
    private function otherEarnings(FPDF $pdf, array $rows): void
    {
        $this->section($pdf, 'Otros haberes del chofer');
        $widths = [28, 45, 82, 35];
        $this->tableHeader($pdf, $widths, ['Fecha', 'Chofer', 'Concepto', 'Importe ARS']);
        if ($rows === []) {
            $this->emptyRow($pdf, 'No hay otros haberes cargados.');
            return;
        }
        foreach ($rows as $row) {
            $this->tableRow($pdf, $widths, [
                $this->date($row['fecha'] ?? null), (string) ($row['empleado'] ?? ''),
                (string) ($row['concepto'] ?? ''), $this->money($row['importe_ars'] ?? 0),
            ], ['L', 'L', 'L', 'R']);
        }
        $pdf->Ln(3);
    }

    /** @param array<string,mixed>|null $settlement */
    private function settlement(FPDF $pdf, ?array $settlement): void
    {
        $this->section($pdf, 'Liquidación del chofer');
        if ($settlement === null) {
            $this->textLine($pdf, 'Sin datos suficientes para calcular la liquidación.');
            return;
        }
        foreach ([
            'Comisión' => 'commission_ars',
            'Otros haberes' => 'other_earnings_ars',
            'Gastos pagados por el chofer' => 'driver_expenses_ars',
            'Gastos pagados con caja' => 'cash_expenses_ars',
            'Anticipos y retiros' => 'advances_ars',
            'Devoluciones' => 'returns_ars',
            'Cuenta corriente' => 'account_balance_ars',
        ] as $label => $field) {
            $this->summaryLine($pdf, $label, $this->money($settlement[$field] ?? 0));
        }
        $this->summaryLine($pdf, 'Total a liquidar', $this->money($settlement['total_ars'] ?? 0), true);
        $pdf->Ln(3);
    }

    /** @param array<string,mixed> $totals @param array<string,mixed>|null $settlement */
    private function profitability(FPDF $pdf, array $totals, ?array $settlement): void
    {
        $this->section($pdf, 'Rendimiento del viaje');
        foreach ([
            'Facturación' => 'invoice_ars',
            'Combustible' => 'fuel_amount_ars',
            'Gastos del viaje' => 'expense_amount_ars',
            'Comisión del chofer' => 'commission_ars',
            'Otros haberes' => 'other_earnings_ars',
            'Costo operativo total' => 'operating_cost_ars',
            'Costos sin asignar a tramo' => 'unassigned_costs_ars',
        ] as $label => $field) {
            $value = $field === 'commission_ars'
                ? ($settlement['commission_ars'] ?? null)
                : ($field === 'other_earnings_ars' ? ($settlement['other_earnings_ars'] ?? null) : ($totals[$field] ?? null));
            $this->summaryLine($pdf, $label, $this->moneyOrNoData($value));
        }
        $margin = $totals['margin_ars'] ?? null;
        $marginText = $this->moneyOrNoData($margin);
        if (($totals['margin_pct'] ?? null) !== null) {
            $marginText .= ' (' . $this->number($totals['margin_pct']) . '%)';
        }
        $this->summaryLine($pdf, 'Margen operativo', $marginText, true);
        $this->textLine($pdf, 'Seguro, cubiertas y amortización: sin dato; no incluidos en este margen.');
    }

    private function title(FPDF $pdf, string $title): void
    {
        $pdf->SetFont('Arial', 'B', 15);
        $pdf->SetTextColor(25, 48, 72);
        $pdf->Cell(0, 9, $this->pdfText($title), 0, 1, 'R');
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(2);
    }

    private function section(FPDF $pdf, string $title): void
    {
        if ($pdf->GetY() > 260) {
            $pdf->AddPage();
        }
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->SetFillColor(231, 237, 243);
        $pdf->Cell(0, 7, $this->pdfText($title), 0, 1, 'L', true);
        $pdf->SetFont('Arial', '', 8);
    }

    /** @param list<float|int> $widths @param list<string> $headers */
    private function tableHeader(FPDF $pdf, array $widths, array $headers): void
    {
        $pdf->SetFont('Arial', 'B', 7.5);
        $pdf->SetFillColor(246, 248, 250);
        foreach ($headers as $index => $header) {
            $pdf->Cell($widths[$index], 7, $this->fit($pdf, $header, (float) $widths[$index] - 2), 1, 0, 'L', true);
        }
        $pdf->Ln();
        $pdf->SetFont('Arial', '', 7.5);
    }

    /** @param list<float|int> $widths @param list<string> $values @param list<string> $alignments */
    private function tableRow(FPDF $pdf, array $widths, array $values, array $alignments): void
    {
        if ($pdf->GetY() > 270) {
            $pdf->AddPage();
        }
        foreach ($values as $index => $value) {
            $pdf->Cell($widths[$index], 6, $this->fit($pdf, (string) $value, (float) $widths[$index] - 2), 1, 0, $alignments[$index] ?? 'L');
        }
        $pdf->Ln();
    }

    private function emptyRow(FPDF $pdf, string $message): void
    {
        $pdf->SetFont('Arial', 'I', 8);
        $pdf->Cell(0, 7, $this->pdfText($message), 0, 1, 'L');
        $pdf->SetFont('Arial', '', 8);
        $pdf->Ln(2);
    }

    private function summaryLine(FPDF $pdf, string $label, string $value, bool $bold = false): void
    {
        $pdf->SetFont('Arial', $bold ? 'B' : '', $bold ? 9 : 8);
        $pdf->Cell(128, 6, $this->pdfText($label), 0, 0, 'L');
        $pdf->Cell(62, 6, $this->pdfText($value), 0, 1, 'R');
        $pdf->SetFont('Arial', '', 8);
    }

    private function textLine(FPDF $pdf, string $text): void
    {
        $pdf->SetFont('Arial', '', 8);
        $pdf->MultiCell(0, 4.5, $this->pdfText($text), 0, 'L');
    }

    /** @param list<array<string,mixed>> $assignments */
    private function assignment(array $assignments, string $role): string
    {
        return (string) ($this->assignmentRow($assignments, $role)['label'] ?? '');
    }

    /** @param list<array<string,mixed>> $assignments @return array<string,mixed> */
    private function assignmentRow(array $assignments, string $role): array
    {
        foreach ($assignments as $assignment) {
            if (($assignment['rol'] ?? null) === $role) {
                return $assignment;
            }
        }

        return [];
    }

    private function fit(FPDF $pdf, string $value, float $width): string
    {
        $text = $this->pdfText($value);
        if ($pdf->GetStringWidth($text) <= $width) {
            return $text;
        }
        while ($text !== '' && $pdf->GetStringWidth($text . '...') > $width) {
            $text = substr($text, 0, -1);
        }

        return rtrim($text) . '...';
    }

    private function pdfText(string $value): string
    {
        $converted = iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $value);

        return $converted === false ? $value : $converted;
    }

    private function date(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '') {
            return 'Sin dato';
        }
        $timestamp = strtotime($value);

        return $timestamp === false ? $value : date('d/m/Y', $timestamp);
    }

    private function number(mixed $value): string
    {
        return number_format((float) $value, 2, ',', '.');
    }

    private function numberOrNoData(mixed $value): string
    {
        return $value === null ? 'Sin dato' : number_format((float) $value, 0, ',', '.');
    }

    private function money(mixed $value): string
    {
        return '$ ' . number_format((float) $value, 2, ',', '.');
    }

    private function moneyOrNoData(mixed $value): string
    {
        return $value === null ? 'Sin dato' : $this->money($value);
    }

    private function paidBy(string $value): string
    {
        return match ($value) {
            'CHOFER' => 'Chofer',
            'CAJA' => 'Caja chofer',
            default => 'Empresa',
        };
    }

    private function cashType(string $value): string
    {
        return match ($value) {
            'ANTICIPO' => 'Anticipo',
            'RETIRO' => 'Retiro',
            'DEVOLUCION' => 'Devolución',
            default => $value ?: 'Sin dato',
        };
    }
}
