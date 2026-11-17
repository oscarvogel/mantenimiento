<?php

declare(strict_types=1);

namespace App\Infrastructure\Trips;

use App\Application\Trips\Port\DriverSettlementPdfRenderer;
use FPDF;

final class DriverSettlementPdfGenerator implements DriverSettlementPdfRenderer
{
    /** @param array<string,mixed> $statement Proyección de DriverSettlementStatement. */
    public function render(array $statement): string
    {
        $record = $statement['trip'] ?? [];
        $totals = $statement['totals'] ?? [];
        $draft = ($statement['status'] ?? null) !== 'DEFINITIVA';

        $pdf = new class('P', 'mm', 'A4') extends FPDF {
            public function Footer(): void
            {
                $this->SetY(-10);
                $this->SetFont('Arial', '', 8);
                $this->SetTextColor(110, 120, 130);
                $this->Cell(0, 5, 'Viaje - Pagina ' . $this->PageNo(), 0, 0, 'R');
            }
        };
        $pdf->SetTitle($this->pdfText('Liquidación del chofer - Viaje #' . ($record['numero'] ?? '')));
        $pdf->SetAuthor('Mantenimiento');
        $pdf->SetMargins(13, 11, 13);
        $pdf->SetAutoPageBreak(true, 11);
        $pdf->AddPage();

        $pdf->SetFont('Arial', 'B', 18);
        $pdf->SetTextColor(25, 48, 72);
        $pdf->Cell(0, 10, $this->pdfText('Liquidación del chofer'), 0, 1, 'L');
        if ($draft) {
            $pdf->SetFillColor(255, 243, 205);
            $pdf->SetTextColor(133, 77, 14);
            $pdf->SetFont('Arial', 'B', 10);
            $pdf->Cell(0, 8, $this->pdfText('BORRADOR - ' . (string) ($statement['status_label'] ?? '')), 0, 1, 'L', true);
        }
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(0, 6, $this->pdfText((string) ($statement['company']['name'] ?? '')), 0, 1, 'L');
        $pdf->Cell(0, 6, $this->pdfText('Viaje #' . ($record['numero'] ?? '') . ' - ' . (string) ($record['sucursal'] ?? '')), 0, 1, 'L');
        $pdf->Cell(0, 6, $this->pdfText('Chofer: ' . ($statement['driver']['label'] ?? 'Sin dato')), 0, 1, 'L');
        $pdf->Cell(0, 6, $this->pdfText('Unidad: ' . ($statement['unit'] ?? 'Sin dato')), 0, 1, 'L');
        $pdf->Cell(0, 6, $this->pdfText('Salida: ' . $this->date($record['fecha_salida_real'] ?? null) . '    Regreso: ' . $this->date($record['fecha_regreso_real'] ?? null)), 0, 1, 'L');
        $pdf->Cell(0, 6, $this->pdfText('Porcentaje del chofer: ' . ($statement['percentage'] === null ? 'Sin definir' : $this->number($statement['percentage']) . ' %')), 0, 1, 'L');
        $pdf->Ln(4);

        $this->section($pdf, 'Base de liquidación y comisión por tramo');
        $widths = [36, 22, 36, 26, 16, 24, 24];
        $this->tableHeader($pdf, $widths, ['Recorrido', 'Modalidad', 'Detalle', 'Base', 'Cotiz.', 'Base ARS', 'Comisión ARS'], 7);
        $legs = $statement['legs'] ?? [];
        if ($legs === []) {
            $this->emptyRow($pdf, 'No hay tramos cargados.');
        }
        foreach ($legs as $leg) {
            $currency = (string) ($leg['moneda'] ?? 'ARS');
            $detail = ($leg['modo'] ?? null) === 'POR_TONELADA'
                ? ($leg['toneladas'] === null || $leg['precio_unitario'] === null
                    ? 'Sin cargar'
                    : $this->number($leg['toneladas'], 3) . ' t x ' . $this->money($leg['precio_unitario'], $currency))
                : 'Importe global';
            $this->tableRow($pdf, $widths, [
                trim((string) ($leg['origen'] ?? '') . ' -> ' . (string) ($leg['destino'] ?? '')),
                (string) ($leg['modo_label'] ?? ''),
                $detail,
                $leg['importe'] === null ? 'Sin dato' : $this->money($leg['importe'], $currency),
                $leg['cotizacion'] === null ? '-' : $this->number($leg['cotizacion'], 4),
                $leg['importe_ars'] === null ? 'Sin dato' : $this->money($leg['importe_ars']),
                $leg['comision_ars'] === null ? 'Sin dato' : $this->money($leg['comision_ars']),
            ], ['L', 'L', 'L', 'R', 'R', 'R', 'R']);
        }
        $this->summaryLine($pdf, 'Base de liquidación total', ($totals['base_ars'] ?? null) === null ? 'Pendiente' : $this->money($totals['base_ars']));
        $this->summaryLine($pdf, 'Comisión total', ($totals['commission_ars'] ?? null) === null ? 'Pendiente' : $this->money($totals['commission_ars']), true);
        $pdf->Ln(2);

        $this->section($pdf, 'Otros haberes');
        $this->tableHeader($pdf, [30, 90, 30, 30], ['Fecha', 'Concepto', 'Importe', 'Importe ARS']);
        $this->moneyRows($pdf, $statement['otherEarnings'] ?? [], 'No hay otros haberes cargados.', static fn (array $row): array => [$row['concepto'] ?? '']);
        $this->summaryLine($pdf, 'Otros haberes total', $this->money(array_sum(array_column($statement['otherEarnings'] ?? [], 'importe_ars'))), true);
        $pdf->Ln(2);

        $this->section($pdf, 'Gastos adelantados por el chofer');
        $this->tableHeader($pdf, [30, 60, 30, 30, 30], ['Fecha', 'Concepto', 'Comprobante', 'Importe', 'Reintegro ARS']);
        $this->moneyRows($pdf, $statement['driverExpenses'] ?? [], 'No hay gastos adelantados por el chofer.', static fn (array $row): array => [$row['concepto'] ?? '', $row['comprobante'] ?? '-']);
        $this->summaryLine($pdf, 'Reintegros total', $this->money(array_sum(array_column($statement['driverExpenses'] ?? [], 'importe_ars'))), true);
        $pdf->Ln(2);

        $this->section($pdf, 'Rendición de caja: anticipos y devoluciones');
        $this->tableHeader($pdf, [30, 90, 30, 30], ['Fecha', 'Movimiento', 'Importe', 'Equivalente ARS']);
        $this->moneyRows($pdf, $statement['cashMovements'] ?? [], 'No hubo movimientos de caja.', static fn (array $row): array => [$row['tipo_label'] ?? '']);

        $this->section($pdf, 'Gastos pagados con caja');
        $this->tableHeader($pdf, [30, 60, 30, 30, 30], ['Fecha', 'Concepto', 'Comprobante', 'Importe', 'Importe ARS']);
        $this->moneyRows($pdf, $statement['cashExpenses'] ?? [], 'No hay gastos pagados con caja.', static fn (array $row): array => [$row['concepto'] ?? '', $row['comprobante'] ?? '-']);
        $this->summaryLine($pdf, 'Caja entregada (caja inicial, anticipos y retiros)', $this->money($totals['cash_delivered_ars'] ?? 0));
        $this->summaryLine($pdf, 'Gastos pagados con caja', $this->money(array_sum(array_column($statement['cashExpenses'] ?? [], 'importe_ars'))));
        $this->summaryLine($pdf, 'Devoluciones', $this->money($totals['cash_returned_ars'] ?? 0));
        $this->summaryLine($pdf, 'Saldo de caja pendiente de rendir', $this->money($totals['cash_balance_ars'] ?? 0), true);
        $pdf->Ln(2);

        $this->section($pdf, 'Resumen');
        $available = (bool) ($statement['available'] ?? false);
        if (! $available) {
            // Sin liquidación completa no se inventa un saldo: se dice qué falta.
            $pdf->SetFillColor(255, 243, 205);
            $pdf->SetFont('Arial', 'B', 11);
            $pdf->Cell(120, 11, $this->pdfText('Saldo final (borrador)'), 1, 0, 'L', true);
            $pdf->Cell(64, 11, $this->pdfText('Pendiente'), 1, 1, 'R', true);
            $pdf->SetFont('Arial', '', 9);
            $pdf->MultiCell(0, 6, $this->pdfText(
                (string) ($statement['pending'] ?? 'Faltan datos para calcular la liquidación.')
                . ' Borrador para revisión: no es válido como liquidación definitiva.',
            ), 0, 'L');

            return $pdf->Output('S');
        }
        $this->summaryLine($pdf, 'Comisión', $this->money($totals['commission_ars'] ?? 0));
        $this->summaryLine($pdf, 'Otros haberes', $this->money($totals['other_earnings_ars'] ?? 0));
        $this->summaryLine($pdf, 'Saldo de cuenta corriente (gastos, caja, anticipos y devoluciones)', $this->money($totals['account_balance_ars'] ?? 0));
        $pdf->Ln(1);

        $pdf->SetFillColor(232, 241, 252);
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell(120, 11, $this->pdfText($draft ? 'Saldo final (borrador)' : 'Saldo final de liquidación'), 1, 0, 'L', true);
        $pdf->Cell(64, 11, $this->pdfText($this->money($totals['total_ars'] ?? 0)), 1, 1, 'R', true);
        $pdf->SetFont('Arial', '', 9);
        $pdf->MultiCell(0, 6, $this->pdfText(
            'Importe positivo: a cobrar por el chofer. Importe negativo: saldo a rendir por el chofer. Importes consolidados en pesos argentinos (ARS).'
            . ($draft ? ' Borrador para revisión: no es válido como liquidación definitiva hasta que el viaje se cierre.' : ''),
        ), 0, 'L');
        $pdf->Ln(4);

        if ($draft) {
            return $pdf->Output('S');
        }

        // Las firmas van juntas: no se separa la línea de su leyenda.
        if ($pdf->GetY() > 255) {
            $pdf->AddPage();
        }
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

    /**
     * Filas con fecha, columnas propias, importe en su moneda y equivalente en pesos.
     *
     * @param list<array<string,mixed>> $rows
     * @param callable(array<string,mixed>):list<mixed> $columns
     */
    private function moneyRows(FPDF $pdf, array $rows, string $empty, callable $columns): void
    {
        if ($rows === []) {
            $this->emptyRow($pdf, $empty);

            return;
        }
        foreach ($rows as $row) {
            $middle = array_map(static fn (mixed $value): string => (string) $value, $columns($row));
            $widths = count($middle) === 1 ? [30, 90, 30, 30] : [30, 60, 30, 30, 30];
            $alignments = count($middle) === 1 ? ['L', 'L', 'R', 'R'] : ['L', 'L', 'L', 'R', 'R'];
            $this->tableRow($pdf, $widths, [
                $this->date($row['fecha'] ?? null),
                ...$middle,
                $this->money($row['importe'] ?? 0, (string) ($row['moneda'] ?? 'ARS')),
                $this->money($row['importe_ars'] ?? 0),
            ], $alignments);
        }
    }

    /** @param list<float|int> $widths @param list<string> $headers */
    private function tableHeader(FPDF $pdf, array $widths, array $headers, int $size = 8): void
    {
        $pdf->SetFont('Arial', 'B', $size);
        $pdf->SetFillColor(240, 244, 248);
        foreach ($headers as $index => $header) {
            $pdf->Cell($widths[$index], 6, $this->pdfText($header), 1, 0, 'L', true);
        }
        $pdf->Ln();
        // Las filas usan la misma letra que su encabezado.
        $pdf->SetFont('Arial', '', $size);
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

    private function date(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '') {
            return 'Sin dato';
        }
        $timestamp = strtotime($value);

        return $timestamp === false ? $value : date('d/m/Y', $timestamp);
    }

    private function number(mixed $value, int $decimals = 2): string
    {
        return number_format((float) $value, $decimals, ',', '.');
    }

    /** Código ISO de la moneda, nunca un símbolo ambiguo como "$". */
    private function money(mixed $value, string $currency = 'ARS'): string
    {
        return strtoupper($currency) . ' ' . number_format((float) $value, 2, ',', '.');
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
