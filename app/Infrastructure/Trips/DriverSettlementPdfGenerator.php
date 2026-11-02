<?php

declare(strict_types=1);

namespace App\Infrastructure\Trips;

use App\Application\Trips\Port\DriverSettlementPdfRenderer;
use FPDF;

final class DriverSettlementPdfGenerator implements DriverSettlementPdfRenderer
{
    /** @param array<string,mixed> $trip */
    public function render(array $trip): string
    {
        $record = $trip['trip'] ?? [];
        $settlement = $trip['settlement'] ?? [];
        $assignments = $trip['assignments'] ?? [];
        $driver = $this->assignment($assignments, 'CHOFER');

        $pdf = new class('P', 'mm', 'A4') extends FPDF {
            public function Footer(): void
            {
                $this->SetY(-10);
                $this->SetFont('Arial', '', 8);
                $this->SetTextColor(110, 120, 130);
                $this->Cell(0, 5, 'Viaje - Pagina ' . $this->PageNo(), 0, 0, 'R');
            }
        };
        $pdf->SetTitle($this->pdfText('Liquidación para el chofer - Viaje #' . ($record['numero'] ?? '')));
        $pdf->SetAuthor('Mantenimiento');
        $pdf->SetMargins(13, 11, 13);
        $pdf->SetAutoPageBreak(true, 11);
        $pdf->AddPage();

        $pdf->SetFont('Arial', 'B', 18);
        $pdf->SetTextColor(25, 48, 72);
        $pdf->Cell(0, 10, $this->pdfText('Liquidación del chofer'), 0, 1, 'L');
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(0, 6, $this->pdfText((string) ($trip['company']['name'] ?? '')), 0, 1, 'L');
        $pdf->Cell(0, 6, $this->pdfText('Viaje #' . ($record['numero'] ?? '') . ' - ' . (string) ($record['sucursal'] ?? '')), 0, 1, 'L');
        $pdf->Cell(0, 6, $this->pdfText('Chofer: ' . ($driver['label'] ?? 'Sin dato')), 0, 1, 'L');
        $pdf->Cell(0, 6, $this->pdfText('Unidad: ' . $this->assignmentLabel($assignments, 'TRACTOR')), 0, 1, 'L');
        $pdf->Cell(0, 6, $this->pdfText('Salida: ' . $this->dateTime($record['fecha_salida_real'] ?? null) . '    Regreso: ' . $this->dateTime($record['fecha_regreso_real'] ?? null)), 0, 1, 'L');
        $pdf->Ln(5);

        $this->section($pdf, 'Comisión por tramo');
        $widths = [92, 38, 22, 28];
        $this->tableHeader($pdf, $widths, ['Recorrido', 'Base liquidación', '%', 'Comisión ARS']);
        $legs = $trip['legs'] ?? [];
        if ($legs === []) {
            $this->emptyRow($pdf, 'No hay tramos cargados.');
        }
        foreach ($legs as $leg) {
            $route = trim((string) ($leg['origen'] ?? '') . ' -> ' . (string) ($leg['destino'] ?? ''));
            $this->tableRow($pdf, $widths, [
                $route,
                $this->money($leg['flete_liquidacion_ars'] ?? 0),
                $this->number($driver['porcentaje_liquidacion'] ?? 0) . '%',
                $this->money($leg['commission_ars'] ?? 0),
            ], ['L', 'R', 'R', 'R']);
        }
        $this->summaryLine($pdf, 'Comisión total', $this->money($settlement['commission_ars'] ?? 0), true);
        $pdf->Ln(2);

        $this->section($pdf, 'Otros haberes');
        $this->tableHeader($pdf, [30, 118, 32], ['Fecha', 'Concepto', 'Importe ARS']);
        $otherEarnings = array_values(array_filter(
            $trip['otherEarnings'] ?? [],
            static fn (array $row): bool => (int) ($row['empleado_id'] ?? 0) === (int) ($driver['empleado_id'] ?? 0),
        ));
        if ($otherEarnings === []) {
            $this->emptyRow($pdf, 'No hay otros haberes cargados.');
        }
        foreach ($otherEarnings as $row) {
            $this->tableRow($pdf, [30, 118, 32], [
                $this->date($row['fecha'] ?? null), (string) ($row['concepto'] ?? ''), $this->money($row['importe_ars'] ?? 0),
            ], ['L', 'L', 'R']);
        }
        $this->summaryLine($pdf, 'Otros haberes total', $this->money($settlement['other_earnings_ars'] ?? 0), true);
        $pdf->Ln(2);

        $this->section($pdf, 'Gastos adelantados por el chofer');
        $this->tableHeader($pdf, [30, 74, 42, 34], ['Fecha', 'Concepto', 'Comprobante', 'Reintegro ARS']);
        $driverExpenses = array_values(array_filter(
            $trip['expenses'] ?? [],
            static fn (array $row): bool => strtoupper((string) ($row['pagado_por'] ?? '')) === 'CHOFER',
        ));
        if ($driverExpenses === []) {
            $this->emptyRow($pdf, 'No hay gastos adelantados por el chofer.');
        }
        foreach ($driverExpenses as $row) {
            $this->tableRow($pdf, [30, 74, 42, 34], [
                $this->date($row['fecha'] ?? null), (string) ($row['categoria'] ?? 'Gasto'),
                (string) ($row['comprobante'] ?? '—'), $this->money($row['importe_ars'] ?? 0),
            ], ['L', 'L', 'L', 'R']);
        }
        $this->summaryLine($pdf, 'Reintegros total', $this->money($settlement['driver_expenses_ars'] ?? 0), true);
        $pdf->Ln(2);

        $this->section($pdf, 'Rendición de caja');
        $this->tableHeader($pdf, [30, 54, 44, 52], ['Fecha', 'Movimiento', 'Importe', 'Equivalente ARS']);
        $cashMovements = $trip['cashMovements'] ?? [];
        if ($cashMovements === []) {
            $this->emptyRow($pdf, 'No hubo movimientos de caja.');
        }
        foreach ($cashMovements as $row) {
            $currency = strtoupper((string) ($row['moneda'] ?? 'ARS'));
            $amount = $currency . ' ' . $this->number($row['importe'] ?? 0);
            $this->tableRow($pdf, [30, 54, 44, 52], [
                $this->date($row['fecha'] ?? null), $this->cashType((string) ($row['tipo'] ?? '')),
                $amount, $this->money($row['importe_ars'] ?? 0),
            ], ['L', 'L', 'R', 'R']);
        }
        $this->section($pdf, 'Gastos pagados con caja');
        $this->tableHeader($pdf, [30, 80, 42, 28], ['Fecha', 'Concepto', 'Comprobante', 'Importe ARS']);
        $cashExpenses = array_values(array_filter(
            $trip['expenses'] ?? [],
            static fn (array $row): bool => strtoupper((string) ($row['pagado_por'] ?? '')) === 'CAJA',
        ));
        if ($cashExpenses === []) {
            $this->emptyRow($pdf, 'No hay gastos pagados con caja.');
        }
        foreach ($cashExpenses as $row) {
            $this->tableRow($pdf, [30, 80, 42, 28], [
                $this->date($row['fecha'] ?? null), (string) ($row['categoria'] ?? 'Gasto'),
                (string) ($row['comprobante'] ?? '-'), $this->money($row['importe_ars'] ?? 0),
            ], ['L', 'L', 'L', 'R']);
        }
        $this->summaryLine($pdf, 'Caja entregada', $this->money($trip['totals']['cash_delivered_ars'] ?? 0));
        $this->summaryLine($pdf, 'Gastos pagados con caja', $this->money($trip['totals']['cash_expenses_ars'] ?? 0));
        $this->summaryLine($pdf, 'Devoluciones', $this->money($trip['totals']['cash_returned_ars'] ?? 0));
        $this->summaryLine($pdf, 'Saldo de caja pendiente de rendir', $this->money($trip['totals']['cash_balance_ars'] ?? 0), true);
        $pdf->Ln(2);

        $pdf->SetFillColor(232, 241, 252);
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell(120, 11, $this->pdfText('Saldo final de liquidación'), 1, 0, 'L', true);
        $pdf->Cell(70, 11, $this->pdfText($this->money($settlement['total_ars'] ?? 0)), 1, 1, 'R', true);
        $pdf->SetFont('Arial', '', 9);
        $pdf->MultiCell(0, 6, $this->pdfText('Importe positivo: a cobrar por el chofer. Importe negativo: saldo a rendir por el chofer.'), 0, 'L');
        $pdf->Ln(4);

        $pdf->Cell(77, 8, '', 'B', 0, 'C');
        $pdf->Cell(26, 8, '', 0, 0);
        $pdf->Cell(77, 8, '', 'B', 1, 'C');
        $pdf->SetFont('Arial', '', 8);
        $pdf->Cell(77, 5, $this->pdfText('Firma del chofer'), 0, 0, 'C');
        $pdf->Cell(26, 5, '', 0, 0);
        $pdf->Cell(77, 5, $this->pdfText('Firma del responsable'), 0, 1, 'C');
        $pdf->Ln(3);
        $pdf->Cell(0, 5, $this->pdfText('Fecha de recepción: ____ / ____ / ______'), 0, 1, 'L');

        return $pdf->Output('S');
    }

    /** @param list<float|int> $widths @param list<string> $headers */
    private function tableHeader(FPDF $pdf, array $widths, array $headers): void
    {
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->SetFillColor(240, 244, 248);
        foreach ($headers as $index => $header) {
            $pdf->Cell($widths[$index], 6, $this->pdfText($header), 1, 0, 'L', true);
        }
        $pdf->Ln();
        $pdf->SetFont('Arial', '', 8);
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

    private function section(FPDF $pdf, string $title): void
    {
        if ($pdf->GetY() > 260) {
            $pdf->AddPage();
        }
        $pdf->Ln(1);
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->SetFillColor(225, 233, 242);
        $pdf->Cell(0, 7, $this->pdfText($title), 0, 1, 'L', true);
        $pdf->SetFont('Arial', '', 8);
    }

    private function summaryLine(FPDF $pdf, string $label, string $value, bool $bold = false): void
    {
        $pdf->SetFont('Arial', $bold ? 'B' : '', $bold ? 9 : 8);
        $pdf->Cell(120, 6, $this->pdfText($label), 0, 0, 'L');
        $pdf->Cell(60, 6, $this->pdfText($value), 0, 1, 'R');
        $pdf->SetFont('Arial', '', 8);
    }

    private function emptyRow(FPDF $pdf, string $message): void
    {
        $pdf->SetFont('Arial', 'I', 8);
        $pdf->Cell(0, 7, $this->pdfText($message), 0, 1, 'L');
        $pdf->SetFont('Arial', '', 8);
    }

    /** @param list<array<string,mixed>> $assignments @return array<string,mixed> */
    private function assignment(array $assignments, string $role): array
    {
        foreach ($assignments as $assignment) {
            if (($assignment['rol'] ?? null) === $role) {
                return $assignment;
            }
        }

        return [];
    }

    /** @param list<array<string,mixed>> $assignments */
    private function assignmentLabel(array $assignments, string $role): string
    {
        return (string) ($this->assignment($assignments, $role)['label'] ?? 'Sin dato');
    }

    private function cashType(string $value): string
    {
        return match ($value) {
            'CAJA_INICIAL' => 'Caja inicial',
            'ANTICIPO' => 'Anticipo',
            'RETIRO' => 'Retiro',
            'DEVOLUCION' => 'Devolución',
            default => $value ?: 'Sin dato',
        };
    }

    private function date(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '') {
            return 'Sin dato';
        }
        $timestamp = strtotime($value);

        return $timestamp === false ? $value : date('d/m/Y', $timestamp);
    }

    private function dateTime(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '') {
            return 'Sin dato';
        }
        $timestamp = strtotime($value);

        return $timestamp === false ? $value : date('d/m/Y H:i', $timestamp);
    }

    private function number(mixed $value): string
    {
        return number_format((float) $value, 2, ',', '.');
    }

    private function money(mixed $value): string
    {
        return '$ ' . number_format((float) $value, 2, ',', '.');
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
}
