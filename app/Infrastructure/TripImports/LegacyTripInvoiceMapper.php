<?php

declare(strict_types=1);

namespace App\Infrastructure\TripImports;

use App\Application\TripImports\Port\TripInvoiceMapper;
use App\Application\TripImports\TripInvoiceLineRow;
use App\Application\TripImports\TripInvoiceRow;
use App\Domain\TripImports\TripField;

final class LegacyTripInvoiceMapper implements TripInvoiceMapper
{
    public function invoice(array $row): TripInvoiceRow
    {
        $data = [];
        $errors = [];
        $warnings = [];

        $key = $this->assign('clave_legacy', TripField::unsignedInt($row['clave'] ?? null, true, 1), $data, $errors, $warnings);
        $this->assign('tipo_comprobante', TripField::unsignedInt($row['tipo_de_comprobante'] ?? null), $data, $errors, $warnings);
        $this->assign('desc_tipo', TripField::text($row['desc_tipo'] ?? null, 10), $data, $errors, $warnings);
        $this->assign('punto_venta', TripField::unsignedInt($row['punto_venta'] ?? null), $data, $errors, $warnings);
        $this->assign('numero_comprobante', TripField::unsignedInt($row['nro_comprobante'] ?? null), $data, $errors, $warnings);
        $date = trim((string) ($row['fecha'] ?? ''));
        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $date, $matches) === 1) {
            $date = $matches[1];
        }
        $this->assign('fecha', TripField::date($date, true), $data, $errors, $warnings);
        $this->assign('cuit_origen', TripField::cuit($row['cuit_empresa'] ?? null), $data, $errors, $warnings);
        $this->assign('codigo_cliente_origen', TripField::unsignedInt($row['codigo_cliente'] ?? null), $data, $errors, $warnings);
        $this->assign('moneda', TripField::text($row['moneda'] ?? null, 5), $data, $errors, $warnings);
        $this->assign('cotizacion', TripField::decimal($row['cotizacion'] ?? null, false, 6), $data, $errors, $warnings);
        $subtotal = $this->assign('subtotal', TripField::decimal($row['subtotal'] ?? null), $data, $errors, $warnings);
        $this->assign('iva', TripField::decimal($row['iva'] ?? null), $data, $errors, $warnings);
        $this->assign('impuestos', TripField::decimal($row['impuestos'] ?? null), $data, $errors, $warnings);
        $this->assign('dgr', TripField::decimal($row['dgr'] ?? null), $data, $errors, $warnings);
        $total = $this->assign('total', TripField::decimal($row['total'] ?? null, true), $data, $errors, $warnings);
        $this->assign('cae', TripField::text($row['cae'] ?? null, 20), $data, $errors, $warnings);

        $data['signo'] = $this->sign($data['desc_tipo'] ?? null);
        $data['total_ars'] = $this->totalArs(
            $total !== null ? (string) $total : null,
            $data['moneda'] ?? null,
            $data['cotizacion'] ?? null,
        );

        return new TripInvoiceRow(is_int($key) ? $key : 0, $data, $errors, $warnings);
    }

    public function line(array $row): TripInvoiceLineRow
    {
        $data = [];
        $errors = [];
        $warnings = [];

        $key = $this->assign('clave_legacy', TripField::unsignedInt($row['clave'] ?? null, true, 1), $data, $errors, $warnings);
        $this->assign('item', TripField::unsignedInt($row['item'] ?? null, true, 1), $data, $errors, $warnings);
        $this->assign('nombre', TripField::text($row['nombre'] ?? null, 200, true), $data, $errors, $warnings);
        $this->assign('cantidad', TripField::decimal($row['cantidad'] ?? null, false, 4), $data, $errors, $warnings);
        $this->assign('precio', TripField::decimal($row['precio_de_venta'] ?? null), $data, $errors, $warnings);
        $this->assign('iva', TripField::decimal($row['iva'] ?? null, false, 4), $data, $errors, $warnings);
        $this->assign('codigo_articulo', TripField::unsignedInt($row['codigo_articulo'] ?? null), $data, $errors, $warnings);
        $this->assign('codigo_alternativo', TripField::text($row['codigo_alternativo'] ?? null, 40), $data, $errors, $warnings);

        $name = (string) ($data['nombre'] ?? '');
        $data['unidad_origen'] = $this->match($name, '/^UNIDAD:\s*(.+)$/i', 20);
        $data['origen_texto'] = $this->match($name, '/^ORIGEN:\s*(.+)$/i', 200);
        $data['destino_texto'] = $this->match($name, '/^DESTINO:\s*(.+)$/i', 200);
        $data['remitos_texto'] = $this->match($name, '/REMITOS?:\s*(.+)$/i', 200);

        return new TripInvoiceLineRow(is_int($key) ? $key : 0, $data, $errors, $warnings);
    }

    private function sign(?string $description): int
    {
        $description = strtoupper(trim((string) $description));
        if (str_starts_with($description, 'NCR') || str_starts_with($description, 'NCP')) {
            return -1;
        }

        return 1;
    }

    private function totalArs(?string $total, ?string $currency, mixed $rate): ?string
    {
        if ($total === null || $total === '') {
            return null;
        }
        $currency = strtoupper(trim((string) $currency));
        $totalValue = (float) $total;
        if ($currency === '' || $currency === 'PES' || $currency === 'ARS') {
            return number_format($totalValue, 4, '.', '');
        }
        $rateValue = $rate === null ? 0.0 : (float) $rate;
        if ($rateValue <= 0.0) {
            return null;
        }

        return number_format($totalValue * $rateValue, 4, '.', '');
    }

    private function match(string $value, string $pattern, int $max): ?string
    {
        if (preg_match($pattern, $value, $matches) !== 1) {
            return null;
        }
        $text = trim($matches[1]);

        return $text === '' ? null : mb_substr($text, 0, $max);
    }

    /**
     * @param array{value:mixed,error:string|null,warning:string|null} $result
     * @param array<string,mixed> $data
     * @param list<array{field:string,message:string}> $errors
     * @param list<array{field:string,message:string}> $warnings
     */
    private function assign(string $field, array $result, array &$data, array &$errors, array &$warnings): mixed
    {
        $data[$field] = $result['value'];
        if ($result['error'] !== null) {
            $errors[] = ['field' => $field, 'message' => $result['error']];
        }
        if ($result['warning'] !== null) {
            $warnings[] = ['field' => $field, 'message' => $result['warning']];
        }

        return $result['value'];
    }
}
