<?php

declare(strict_types=1);

namespace App\Infrastructure\TripImports;

use App\Application\TripImports\Port\TripRowMapper;
use App\Application\TripImports\TripChildRow;
use App\Application\TripImports\TripRow;
use App\Domain\TripImports\TripField;
use App\Domain\TripImports\TripKey;
use DomainException;

final class LegacyTripRowMapper implements TripRowMapper
{
    private const CHILD_TABLES = [
        't06_origenes_destinos_por_viaje' => 'viaje_tramos',
        't07_cargas_combustible_por_viaje' => 'viaje_combustible',
        't08_gastos_por_viaje' => 'viaje_gastos',
        't11_cuenta_corriente_x_viaje' => 'viaje_cuenta_corriente',
        't12_cuenta_corriente_origenes_destinos_por_viaje' => 'viaje_cuenta_corriente_tramos',
        't13_otros_haberes_por_viaje' => 'viaje_otros_haberes',
    ];

    public function trip(array $row): TripRow
    {
        $data = [];
        $errors = [];
        $warnings = [];

        $cuit = $this->assign('cuit_origen', TripField::cuit($row['cuit_empresa'] ?? null), $data, $errors, $warnings);
        $domain = $this->assign('dominio_origen', TripField::domain($row['dominio'] ?? null), $data, $errors, $warnings);
        $number = $this->assign(
            'numero_viaje',
            TripField::unsignedInt($row['numero_viaje'] ?? null, true, 1),
            $data,
            $errors,
            $warnings,
        );
        $this->assign('fecha_salida', TripField::date($row['fecha_salida'] ?? null, true), $data, $errors, $warnings);
        $returnDate = $this->assign(
            'fecha_regreso',
            TripField::date($row['fecha_regreso'] ?? null),
            $data,
            $errors,
            $warnings,
        );
        if (($data['fecha_salida'] ?? null) !== null && $returnDate !== null && $returnDate < $data['fecha_salida']) {
            $warnings[] = [
                'field' => 'fecha_regreso',
                'message' => 'es anterior a la fecha de salida; se conserva para revision.',
            ];
        }
        $this->assign(
            'legajo_chofer_origen',
            TripField::text($row['legajo_chofer'] ?? null, 13),
            $data,
            $errors,
            $warnings,
        );
        $this->assign('observaciones_1', TripField::text($row['observaciones_1'] ?? null, 100), $data, $errors, $warnings);
        $this->assign('observaciones_2', TripField::text($row['observaciones_2'] ?? null, 100), $data, $errors, $warnings);
        $this->assign('observaciones_3', TripField::text($row['observaciones_3'] ?? null, 100), $data, $errors, $warnings);
        $this->assign('km_salida', TripField::unsignedInt($row['km_salida'] ?? null), $data, $errors, $warnings);
        $this->assign('km_regreso', TripField::unsignedInt($row['km_regreso'] ?? null), $data, $errors, $warnings);
        $this->assign('contabilizado', TripField::flag($row['contabilizado'] ?? null), $data, $errors, $warnings);
        $this->assign(
            'fecha_contabilizacion',
            TripField::date($row['fecha_contabilizacion'] ?? null),
            $data,
            $errors,
            $warnings,
        );
        $this->assign(
            'porcentaje_chofer',
            TripField::decimal($row['porcentaje_chofer'] ?? null, false, 2),
            $data,
            $errors,
            $warnings,
        );
        $this->assign('marcar', TripField::flag($row['marcar'] ?? null), $data, $errors, $warnings);

        $key = TripKey::make(
            is_string($cuit) ? $cuit : null,
            is_string($domain) ? $domain : null,
            is_int($number) ? $number : null,
        );
        if ($key === '') {
            $errors[] = ['field' => 'clave', 'message' => 'no se pudo formar la clave del viaje.'];
        }

        return new TripRow($key, is_string($domain) ? $domain : '', $data, $errors, $warnings);
    }

    public function child(string $legacyTable, array $row): TripChildRow
    {
        if (! isset(self::CHILD_TABLES[$legacyTable])) {
            throw new DomainException('Tabla hija de viajes no reconocida: ' . $legacyTable);
        }
        $table = self::CHILD_TABLES[$legacyTable];
        $data = [];
        $errors = [];
        $warnings = [];

        $cuit = TripField::cuit($row['cuit_empresa'] ?? null);
        $domain = TripField::domain($row['dominio'] ?? null);
        $number = TripField::unsignedInt($row['numero_viaje'] ?? null, true, 1);
        foreach ([
            'cuit_empresa' => $cuit,
            'dominio' => $domain,
            'numero_viaje' => $number,
        ] as $field => $result) {
            if ($result['error'] !== null) {
                $errors[] = ['field' => $field, 'message' => $result['error']];
            }
        }
        $tripKey = $errors === []
            ? TripKey::make(
                is_string($cuit['value']) ? $cuit['value'] : null,
                is_string($domain['value']) ? $domain['value'] : null,
                is_int($number['value']) ? $number['value'] : null,
            )
            : '';

        $this->assign('item', TripField::unsignedInt($row['item'] ?? null, true, 0), $data, $errors, $warnings);

        match ($table) {
            'viaje_tramos' => $this->tramos($row, $data, $errors, $warnings),
            'viaje_combustible' => $this->combustible($row, $data, $errors, $warnings),
            'viaje_gastos' => $this->gastos($row, $data, $errors, $warnings),
            'viaje_cuenta_corriente' => $this->cuentaCorriente($row, $data, $errors, $warnings),
            'viaje_cuenta_corriente_tramos' => $this->cuentaCorrienteTramos($row, $data, $errors, $warnings),
            'viaje_otros_haberes' => $this->otrosHaberes($row, $data, $errors, $warnings),
            default => throw new DomainException('Tabla hija de viajes no reconocida: ' . $table),
        };

        return new TripChildRow($table, $tripKey, $data, $errors, $warnings);
    }

    private function tramos(array $row, array &$data, array &$errors, array &$warnings): void
    {
        $this->assign(
            'codigo_origen_destino',
            TripField::unsignedInt($row['codigo_origen_destino'] ?? null),
            $data,
            $errors,
            $warnings,
        );
        $this->assign(
            'cantidad_kilometros',
            TripField::decimal($row['cantidad_kilometros'] ?? null, false, 2),
            $data,
            $errors,
            $warnings,
        );
        $this->assign('toneladas', TripField::decimal($row['toneladas'] ?? null, false, 2), $data, $errors, $warnings);
        $this->assign('precio', TripField::decimal($row['precio'] ?? null), $data, $errors, $warnings);
        $this->assign('importe', TripField::decimal($row['importe'] ?? null), $data, $errors, $warnings);
        $this->assign('importe_inicial', TripField::decimal($row['importe_inicial'] ?? null), $data, $errors, $warnings);
        $this->assign(
            'porcentaje_facturacion',
            TripField::decimal($row['porcentaje_facturacion'] ?? null, false, 2),
            $data,
            $errors,
            $warnings,
        );
        $this->assign(
            'descripcion_origen',
            TripField::text($row['descripcion_origen'] ?? null, 120),
            $data,
            $errors,
            $warnings,
        );
        $this->assign(
            'descripcion_destino',
            TripField::text($row['descripcion_destino'] ?? null, 120),
            $data,
            $errors,
            $warnings,
        );
        foreach ([1, 2, 3, 4] as $index) {
            $this->assign(
                'observaciones_' . $index,
                TripField::text($row['observaciones' . $index] ?? null, 120),
                $data,
                $errors,
                $warnings,
            );
        }
        $this->assign('remito', TripField::text($row['remito'] ?? null, 60), $data, $errors, $warnings);
        $this->assign('cobrado', TripField::flag($row['cobrado'] ?? null), $data, $errors, $warnings);
        $this->assign(
            'codigo_alternativo',
            TripField::text($row['codigo_alternativo'] ?? null, 40),
            $data,
            $errors,
            $warnings,
        );
        $this->assign(
            'codigo_cliente_origen',
            TripField::text($row['codigo_cliente'] ?? null, 40),
            $data,
            $errors,
            $warnings,
        );
        $this->assign('fecha_salida', TripField::date($row['fecha_salida'] ?? null), $data, $errors, $warnings);
        $this->assign('fecha_llegada', TripField::date($row['fecha_llegada'] ?? null), $data, $errors, $warnings);
    }

    private function combustible(array $row, array &$data, array &$errors, array &$warnings): void
    {
        $this->assign(
            'codigo_concepto',
            TripField::text($row['codigo_concepto'] ?? null, 40),
            $data,
            $errors,
            $warnings,
        );
        $this->assign('litros', TripField::decimal($row['litros'] ?? null, false, 2), $data, $errors, $warnings);
        $this->assign('importe', TripField::decimal($row['importe'] ?? null), $data, $errors, $warnings);
        $this->assign('observaciones', TripField::text($row['observaciones'] ?? null, 120), $data, $errors, $warnings);
        $this->assign('fecha', TripField::date($row['fecha'] ?? null), $data, $errors, $warnings);
        $this->assign(
            'kilometraje_actual',
            TripField::unsignedInt($row['kilometraje_actual'] ?? null),
            $data,
            $errors,
            $warnings,
        );
    }

    private function gastos(array $row, array &$data, array &$errors, array &$warnings): void
    {
        $this->assign(
            'codigo_concepto',
            TripField::text($row['codigo_concepto'] ?? null, 40),
            $data,
            $errors,
            $warnings,
        );
        $this->assign('importe', TripField::decimal($row['importe'] ?? null), $data, $errors, $warnings);
        $this->assign('observaciones', TripField::text($row['observaciones'] ?? null, 120), $data, $errors, $warnings);
        $this->assign('fecha', TripField::date($row['fecha'] ?? null), $data, $errors, $warnings);
    }

    private function cuentaCorriente(array $row, array &$data, array &$errors, array &$warnings): void
    {
        $this->assign(
            'codigo_concepto',
            TripField::text($row['codigo_concepto'] ?? null, 40),
            $data,
            $errors,
            $warnings,
        );
        foreach (['debitos', 'creditos', 'debitos_moneda', 'creditos_moneda', 'importe_moneda'] as $field) {
            $this->assign($field, TripField::decimal($row[$field] ?? null), $data, $errors, $warnings);
        }
        $this->assign('cotizacion', TripField::decimal($row['cotizacion'] ?? null, false, 6), $data, $errors, $warnings);
        $this->assign('id_moneda', TripField::unsignedInt($row['id_moneda'] ?? null), $data, $errors, $warnings);
        $this->assign('observaciones', TripField::text($row['observaciones'] ?? null, 120), $data, $errors, $warnings);
        $this->assign('fecha', TripField::date($row['fecha'] ?? null), $data, $errors, $warnings);
    }

    private function cuentaCorrienteTramos(array $row, array &$data, array &$errors, array &$warnings): void
    {
        $this->assign('sub_item', TripField::unsignedInt($row['sub_item'] ?? null, true, 0), $data, $errors, $warnings);
        $this->assign(
            'tipo_comprobante',
            TripField::text($row['tipo_comprobante'] ?? null, 40),
            $data,
            $errors,
            $warnings,
        );
        $this->assign('punto_venta', TripField::text($row['punto_venta'] ?? null, 20), $data, $errors, $warnings);
        $this->assign(
            'numero_comprobante',
            TripField::text($row['numero_comprobante'] ?? null, 30),
            $data,
            $errors,
            $warnings,
        );
        $this->assign('importe', TripField::decimal($row['importe'] ?? null), $data, $errors, $warnings);
        $this->assign('fecha', TripField::date($row['fecha'] ?? null), $data, $errors, $warnings);
    }

    private function otrosHaberes(array $row, array &$data, array &$errors, array &$warnings): void
    {
        $this->assign(
            'codigo_concepto',
            TripField::text($row['codigo_concepto'] ?? null, 40),
            $data,
            $errors,
            $warnings,
        );
        $this->assign('importe', TripField::decimal($row['importe'] ?? null), $data, $errors, $warnings);
        $this->assign('observaciones', TripField::text($row['observaciones'] ?? null, 120), $data, $errors, $warnings);
        $this->assign('fecha', TripField::date($row['fecha'] ?? null), $data, $errors, $warnings);
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
