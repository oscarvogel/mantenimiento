<?php

declare(strict_types=1);

namespace App\Infrastructure\CustomerAccounts;

use App\Application\CustomerAccounts\Port\CustomerReceiptPdfRenderer;
use FPDF;

/**
 * Comprobante imprimible del recibo de cobranza (#536).
 *
 * Es un documento interno: no simula un comprobante fiscal autorizado por
 * ARCA. Un recibo anulado se imprime con su estado y su motivo bien visibles
 * para que no pueda confundirse con uno vigente.
 */
final class FpdfCustomerReceiptPdfGenerator implements CustomerReceiptPdfRenderer
{
    public function render(array $receipt): string
    {
        $header = $receipt['receipt'] ?? [];
        $company = $receipt['company'] ?? [];
        $customer = $receipt['customer'] ?? [];
        $branch = $receipt['branch'] ?? [];
        $state = (string) ($header['estado'] ?? 'BORRADOR');

        $pdf = new class ('P', 'mm', 'A4') extends FPDF {
            public function Footer(): void
            {
                $this->SetY(-11);
                $this->SetFont('Arial', '', 7);
                $this->SetTextColor(110, 120, 130);
                $this->Cell(0, 4, $this->encode('Recibo interno de cobranza - Página ' . $this->PageNo()), 0, 0, 'R');
            }

            public function encode(string $value): string
            {
                $converted = iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $value);

                return $converted === false ? $value : $converted;
            }
        };
        $pdf->SetTitle($pdf->encode('Recibo de cobranza ' . ($header['numero_visible'] ?? '')));
        $pdf->SetAuthor('Mantenimiento');
        $pdf->SetMargins(13, 12, 13);
        $pdf->SetAutoPageBreak(true, 14);
        $pdf->AddPage();

        // Cabecera de la empresa y del documento. No hay un archivo de logo en
        // el maestro de empresas: se usa una marca tipográfica estable con las
        // iniciales, el nombre y el CUIT.
        $pdf->SetFillColor(25, 48, 72);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell(20, 13, $pdf->encode($this->initials((string) ($company['razon_social'] ?? $this->companyName($company)))), 1, 0, 'C', true);
        $pdf->SetFont('Arial', 'B', 14);
        $pdf->Cell(92, 13, $pdf->encode($this->companyName($company)), 0, 0, 'L', true);
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell(72, 13, $pdf->encode('RECIBO DE COBRANZA'), 0, 1, 'R', true);
        $pdf->SetTextColor(30, 30, 30);
        $pdf->SetFont('Arial', '', 9);
        if (($company['cuit'] ?? null) !== null && $company['cuit'] !== '') {
            $pdf->Cell(112, 5, $pdf->encode('CUIT ' . $company['cuit']), 0, 0, 'L');
        } else {
            $pdf->Cell(112, 5, '', 0, 0, 'L');
        }
        $pdf->Cell(72, 5, $pdf->encode('N° ' . ($header['numero_visible'] ?? 'Borrador')), 0, 1, 'R');
        $pdf->Cell(112, 5, $pdf->encode('Sucursal: ' . ($branch['nombre'] ?? '')), 0, 0, 'L');
        $pdf->Cell(72, 5, $pdf->encode('Emisión: ' . $this->date($header['fecha_emision'] ?? null)), 0, 1, 'R');

        if ($state === 'ANULADO') {
            $pdf->SetFillColor(254, 226, 226);
            $pdf->SetTextColor(153, 27, 27);
            $pdf->SetFont('Arial', 'B', 13);
            $pdf->Cell(0, 10, $pdf->encode('RECIBO ANULADO - NO VÁLIDO COMO COBRO'), 1, 1, 'C', true);
            $pdf->SetFont('Arial', '', 9);
            $pdf->Cell(0, 6, $pdf->encode('Motivo: ' . (string) ($header['motivo_anulacion'] ?? 'Sin detalle')), 0, 1, 'L');
            $pdf->SetTextColor(30, 30, 30);
        } elseif ($state === 'BORRADOR') {
            $pdf->SetFillColor(255, 243, 205);
            $pdf->SetTextColor(133, 77, 14);
            $pdf->SetFont('Arial', 'B', 11);
            $pdf->Cell(0, 9, $pdf->encode('BORRADOR - Todavía no tiene número ni efectos contables'), 1, 1, 'C', true);
            $pdf->SetTextColor(30, 30, 30);
        }

        $pdf->Ln(3);
        $this->section($pdf, 'Cliente');
        $pdf->SetFont('Arial', '', 9);
        $pdf->Cell(100, 6, $pdf->encode('Nombre: ' . (string) ($customer['nombre'] ?? '')), 0, 0, 'L');
        $pdf->Cell(84, 6, $pdf->encode('CUIT / ID: ' . (string) ($customer['identificacion_fiscal'] ?? 'Sin dato')), 0, 1, 'L');
        if (($customer['direccion'] ?? null) !== null && $customer['direccion'] !== '') {
            $pdf->Cell(0, 5, $pdf->encode('Domicilio: ' . $customer['direccion']), 0, 1, 'L');
        }
        $pdf->Cell(100, 5, $pdf->encode('Recepción efectiva: ' . $this->date($header['fecha_recepcion'] ?? null)), 0, 0, 'L');
        $pdf->Cell(84, 5, $pdf->encode('Responsable: ' . (string) ($header['usuario'] ?? 'Sin dato')), 0, 1, 'L');

        $pdf->Ln(2);
        $this->section($pdf, 'Medios de pago');
        $widths = [30, 18, 28, 22, 26, 15, 37];
        $this->tableHeader($pdf, $widths, ['Medio', 'Moneda', 'Importe', 'Cotización', 'Equivalente ARS', 'Estado', 'Detalle']);
        foreach ($receipt['instruments'] ?? [] as $instrument) {
            $detail = $this->instrumentDetail($instrument);
            $this->tableRow($pdf, $widths, [
                (string) ($instrument['medio_label'] ?? ''),
                (string) ($instrument['moneda'] ?? ''),
                $this->number($instrument['importe'] ?? 0),
                $this->number($instrument['cotizacion'] ?? 1, 4),
                $this->number($instrument['importe_ars'] ?? 0),
                $this->shortState((string) ($instrument['estado_acreditacion'] ?? '')),
                $detail,
            ], ['L', 'L', 'R', 'R', 'R', 'L', 'L']);
        }

        $pdf->Ln(2);
        $this->section($pdf, 'Documentos imputados');
        $widths = [55, 30, 28, 71];
        $this->tableHeader($pdf, $widths, ['Documento', 'Fecha', 'Estado', 'Importe imputado']);
        $imputations = array_values(array_filter($receipt['imputations'] ?? [], static fn (array $row): bool => (string) ($row['estado'] ?? '') === 'VIGENTE'));
        if ($imputations === []) {
            $pdf->SetFont('Arial', 'I', 8);
            $pdf->Cell(0, 7, $pdf->encode('El pago no se imputó a documentos: queda como anticipo a favor del cliente.'), 0, 1, 'L');
        }
        foreach ($imputations as $imputation) {
            $this->tableRow($pdf, $widths, [
                (string) ($imputation['documento'] ?? ''),
                $this->date($imputation['documento_fecha'] ?? null),
                'Imputado',
                $this->number($imputation['importe_ars'] ?? 0),
            ], ['L', 'L', 'L', 'R']);
        }

        $pdf->Ln(2);
        $this->summaryLine($pdf, 'Total del recibo', $this->money($header['total_ars'] ?? 0), true);
        $this->summaryLine($pdf, 'Acreditado en cuenta corriente', $this->money($header['acreditado_ars'] ?? 0));
        $this->summaryLine($pdf, 'Pendiente de acreditación', $this->money($header['pendiente_ars'] ?? 0));
        $this->summaryLine($pdf, 'Imputado a documentos', $this->money($header['imputado_ars'] ?? 0));
        $this->summaryLine($pdf, 'Saldo disponible (anticipo)', $this->money($header['disponible_ars'] ?? 0));
        $pdf->Ln(2);
        $this->summaryLine($pdf, 'Saldo anterior del cliente', $this->money($header['saldo_anterior_ars'] ?? 0));
        $this->summaryLine($pdf, 'Saldo posterior', $this->money($header['saldo_posterior_ars'] ?? 0), true);
        if (abs((float) ($header['saldo_posterior_previsto_ars'] ?? 0) - (float) ($header['saldo_posterior_ars'] ?? 0)) >= 0.01) {
            $this->summaryLine($pdf, 'Saldo posterior si se acredita todo lo pendiente', $this->money($header['saldo_posterior_previsto_ars'] ?? 0));
        }

        if (($header['observaciones'] ?? null) !== null && $header['observaciones'] !== '') {
            $pdf->Ln(3);
            $this->section($pdf, 'Observaciones');
            $pdf->SetFont('Arial', '', 9);
            $pdf->MultiCell(0, 5, $pdf->encode((string) $header['observaciones']), 1, 'L');
        }

        $pdf->Ln(4);
        $pdf->SetFont('Arial', 'I', 7);
        $pdf->SetTextColor(90, 90, 90);
        $pdf->MultiCell(
            0,
            4,
            $pdf->encode(
                'Documento interno de cobranza. No es un comprobante fiscal autorizado por ARCA. '
                . 'Los importes en moneda extranjera conservan la cotización congelada al momento del cobro. '
                . 'Este recibo puede anularse únicamente con contraasientos en la cuenta corriente.',
            ),
            0,
            'L',
        );

        return $pdf->Output('S');
    }

    private function companyName(array $company): string
    {
        $name = trim((string) ($company['nombre_fantasia'] ?? ''));

        return $name !== '' ? $name : (string) ($company['razon_social'] ?? 'Empresa');
    }

    /** Marca tipográfica estable: no hay archivo de logo en el maestro. */
    private function initials(string $name): string
    {
        $words = preg_split('/\s+/', trim($name)) ?: [];
        $initials = '';
        foreach (array_slice(array_values(array_filter($words, static fn (string $word): bool => $word !== '')), 0, 3) as $word) {
            $initials .= mb_strtoupper(mb_substr($word, 0, 1));
        }

        return $initials === '' ? 'EM' : $initials;
    }

    private function instrumentDetail(array $instrument): string
    {
        if (($instrument['cheque'] ?? null) !== null) {
            $cheque = $instrument['cheque'];
            $parts = [
                'Cheque ' . (string) ($cheque['numero'] ?? ''),
                'Banco ' . (string) ($cheque['banco'] ?? ''),
                'Librador ' . (string) ($cheque['librador'] ?? ''),
                'Vence ' . $this->date($cheque['fecha_pago'] ?? null),
                (string) ($cheque['estado_label'] ?? ''),
            ];

            return implode(' · ', array_filter($parts, static fn (string $part): bool => trim($part) !== ''));
        }
        if ((string) ($instrument['medio'] ?? '') === 'TRANSFERENCIA') {
            $parts = [
                'Banco ' . (string) ($instrument['banco'] ?? ''),
                'Ref. ' . (string) ($instrument['referencia'] ?? ''),
                'Fecha ' . $this->date($instrument['fecha_transferencia'] ?? null),
                (string) ($instrument['verification_label'] ?? ''),
            ];

            return implode(' · ', array_filter($parts, static fn (string $part): bool => trim($part) !== ''));
        }

        return (string) ($instrument['observaciones'] ?? '');
    }

    private function shortState(string $state): string
    {
        return match ($state) {
            'ACREDITADO' => 'Acreditado',
            'ANULADO' => 'Anulado',
            default => 'Pendiente',
        };
    }

    private function section(FPDF $pdf, string $title): void
    {
        if ($pdf->GetY() > 258) {
            $pdf->AddPage();
        }
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->SetFillColor(230, 237, 245);
        $pdf->Cell(0, 7, $pdf->encode($title), 0, 1, 'L', true);
        $pdf->SetFont('Arial', '', 8);
    }

    /** @param list<float|int> $widths @param list<string> $headers */
    private function tableHeader(FPDF $pdf, array $widths, array $headers): void
    {
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->SetFillColor(240, 244, 248);
        foreach ($headers as $index => $header) {
            $pdf->Cell($widths[$index], 6, $pdf->encode($header), 1, 0, 'L', true);
        }
        $pdf->Ln();
        $pdf->SetFont('Arial', '', 8);
    }

    /** @param list<float|int> $widths @param list<string> $values @param list<string> $alignments */
    private function tableRow(FPDF $pdf, array $widths, array $values, array $alignments): void
    {
        if ($pdf->GetY() > 268) {
            $pdf->AddPage();
        }
        foreach ($values as $index => $value) {
            $pdf->Cell($widths[$index], 6, $this->fit($pdf, (string) $value, (float) $widths[$index] - 2), 1, 0, $alignments[$index] ?? 'L');
        }
        $pdf->Ln();
    }

    private function summaryLine(FPDF $pdf, string $label, string $value, bool $bold = false): void
    {
        $pdf->SetFont('Arial', $bold ? 'B' : '', $bold ? 10 : 9);
        $pdf->Cell(118, 6, $pdf->encode($label), 0, 0, 'L');
        $pdf->Cell(66, 6, $pdf->encode($value), 0, 1, 'R');
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

    private function money(mixed $value): string
    {
        return 'ARS ' . $this->number($value);
    }

    private function fit(FPDF $pdf, string $value, float $width): string
    {
        $text = $pdf->encode($value);
        if ($pdf->GetStringWidth($text) <= $width) {
            return $text;
        }
        while ($text !== '' && $pdf->GetStringWidth($text . '...') > $width) {
            $text = substr($text, 0, -1);
        }

        return rtrim($text) . '...';
    }
}
