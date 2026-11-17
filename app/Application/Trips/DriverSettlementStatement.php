<?php

declare(strict_types=1);

namespace App\Application\Trips;

use App\Domain\Trips\Trip;
use App\Domain\Trips\TripCashMovement;
use App\Domain\Trips\TripLegLiquidationBasis;
use DomainException;

/**
 * Proyección segura de la liquidación del chofer (#533).
 *
 * Es lo único que reciben la vista «Liquidación del chofer» y su PDF. Se arma
 * por lista blanca a partir del detalle administrativo que ya calcula
 * {@see GetOperationalTrip}: no hay un segundo cálculo de comisión ni de saldo,
 * y nada que no esté nombrado acá llega al navegador ni al PDF.
 *
 * Quedan fuera a propósito: flete de facturación, cliente, cotización de
 * facturación, costos asignados, margen y rentabilidad, diferencias entre
 * facturación y liquidación, combustible pagado por la empresa, observaciones
 * internas y datos de otros choferes.
 */
final class DriverSettlementStatement
{
    public const BORRADOR = 'BORRADOR';
    public const DEFINITIVA = 'DEFINITIVA';

    /**
     * @param array<string,mixed> $detail Detalle administrativo de GetOperationalTrip.
     * @return array<string,mixed>
     */
    public static function fromOperationalTrip(array $detail): array
    {
        $trip = $detail['trip'] ?? [];
        $status = (string) ($trip['estado'] ?? '');
        if ($status === Trip::CANCELADO) {
            throw new DomainException('Un viaje cancelado no tiene liquidación del chofer.');
        }
        $definitive = $status === Trip::FINALIZADO;
        $totals = $detail['totals'] ?? [];
        $settlement = $detail['settlement'] ?? null;

        $drivers = array_values(array_filter(
            $detail['assignments'] ?? [],
            static fn (array $row): bool => ($row['rol'] ?? '') === 'CHOFER',
        ));
        $driver = count($drivers) === 1 ? $drivers[0] : null;
        $driverId = $driver === null || ($driver['empleado_id'] ?? null) === null ? null : (int) $driver['empleado_id'];
        $percentage = $totals['driver_percentage'] ?? null;

        $expenses = $detail['expenses'] ?? [];
        $paidBy = static fn (array $row): string => strtoupper((string) ($row['pagado_por'] ?? ''));

        return [
            'status' => $definitive ? self::DEFINITIVA : self::BORRADOR,
            'status_label' => $definitive
                ? 'El viaje está cerrado: los importes son los definitivos.'
                : 'El viaje todavía no está cerrado y los importes pueden cambiar.',
            'company' => ['name' => (string) ($detail['company']['name'] ?? '')],
            'trip' => [
                'id' => (int) ($trip['id'] ?? 0),
                'numero' => $trip['numero'] ?? null,
                'estado' => $status,
                'estado_label' => $trip['estado_label'] ?? $status,
                'sucursal' => $trip['sucursal'] ?? null,
                'fecha_salida_real' => $trip['fecha_salida_real'] ?? null,
                'fecha_regreso_real' => $trip['fecha_regreso_real'] ?? null,
            ],
            'driver' => $driver === null ? null : [
                'id' => $driverId,
                'label' => $driver['label'] ?? null,
            ],
            'unit' => self::assignmentLabel($detail['assignments'] ?? [], 'TRACTOR'),
            'percentage' => $percentage === null ? null : (float) $percentage,
            'legs' => array_map(
                static fn (array $leg): array => self::leg($leg),
                $detail['legs'] ?? [],
            ),
            'otherEarnings' => $driverId === null ? [] : array_values(array_map(
                static fn (array $row): array => self::money($row, [
                    'fecha' => $row['fecha'] ?? null,
                    'concepto' => (string) ($row['concepto'] ?? ''),
                ]),
                array_filter(
                    $detail['otherEarnings'] ?? [],
                    static fn (array $row): bool => (int) ($row['empleado_id'] ?? 0) === $driverId,
                ),
            )),
            'driverExpenses' => self::expenses($expenses, static fn (array $row): bool => $paidBy($row) === 'CHOFER'),
            'cashExpenses' => self::expenses($expenses, static fn (array $row): bool => $paidBy($row) === 'CAJA'),
            'cashMovements' => array_map(
                static fn (array $row): array => self::money($row, [
                    'fecha' => $row['fecha'] ?? null,
                    'tipo' => (string) ($row['tipo'] ?? ''),
                    'tipo_label' => self::cashType((string) ($row['tipo'] ?? '')),
                ]),
                $detail['cashMovements'] ?? [],
            ),
            'totals' => [
                'base_ars' => $totals['liquidation_ars'] ?? null,
                'commission_ars' => $settlement['commission_ars'] ?? null,
                'other_earnings_ars' => $settlement['other_earnings_ars'] ?? null,
                'driver_expenses_ars' => $settlement['driver_expenses_ars'] ?? null,
                'cash_expenses_ars' => $settlement['cash_expenses_ars'] ?? null,
                'advances_ars' => $settlement['advances_ars'] ?? null,
                'returns_ars' => $settlement['returns_ars'] ?? null,
                'account_balance_ars' => $settlement['account_balance_ars'] ?? null,
                'total_ars' => $settlement['total_ars'] ?? null,
                'cash_delivered_ars' => $totals['cash_delivered_ars'] ?? 0.0,
                'cash_returned_ars' => $totals['cash_returned_ars'] ?? 0.0,
                'cash_balance_ars' => $totals['cash_balance_ars'] ?? 0.0,
            ],
            'available' => $settlement !== null,
            'pending' => $settlement !== null ? null : self::pendingReason($totals, $detail['legs'] ?? []),
        ];
    }

    /** @param array<string,mixed> $leg @return array<string,mixed> */
    private static function leg(array $leg): array
    {
        $mode = (string) ($leg['modo_liquidacion'] ?? TripLegLiquidationBasis::MONTO_ACORDADO);
        $currency = strtoupper((string) ($leg['moneda_liquidacion'] ?? 'ARS'));
        $perTonne = $mode === TripLegLiquidationBasis::POR_TONELADA;

        return [
            'orden' => (int) ($leg['orden'] ?? 0),
            'origen' => (string) ($leg['origen'] ?? ''),
            'destino' => (string) ($leg['destino'] ?? ''),
            'fecha_inicio' => $leg['fecha_inicio'] ?? null,
            'fecha_fin' => $leg['fecha_fin'] ?? null,
            'modo' => $perTonne ? TripLegLiquidationBasis::POR_TONELADA : TripLegLiquidationBasis::MONTO_ACORDADO,
            'modo_label' => TripLegLiquidationBasis::labelFor($mode),
            'toneladas' => $perTonne ? ($leg['toneladas_liquidacion'] ?? null) : null,
            'precio_unitario' => $perTonne ? ($leg['precio_unitario_liquidacion'] ?? null) : null,
            'moneda' => $currency,
            'importe' => $leg['flete_liquidacion'] ?? null,
            // La cotización solo se muestra cuando explica la conversión: en
            // pesos no hay nada que convertir.
            'cotizacion' => $currency === 'ARS' ? null : ($leg['cotizacion_liquidacion'] ?? null),
            'importe_ars' => $leg['flete_liquidacion_ars'] ?? null,
            'comision_ars' => $leg['commission_ars'] ?? null,
        ];
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param callable(array<string,mixed>):bool $filter
     * @return list<array<string,mixed>>
     */
    private static function expenses(array $rows, callable $filter): array
    {
        return array_values(array_map(
            static fn (array $row): array => self::money($row, [
                'fecha' => $row['fecha'] ?? null,
                'concepto' => (string) ($row['concepto_catalogo'] ?? $row['categoria'] ?? 'Gasto'),
                'comprobante' => $row['comprobante'] ?? null,
            ]),
            array_filter($rows, $filter),
        ));
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $fields
     * @return array<string,mixed>
     */
    private static function money(array $row, array $fields): array
    {
        $currency = strtoupper((string) ($row['moneda'] ?? 'ARS'));

        return $fields + [
            'importe' => (float) ($row['importe'] ?? $row['importe_ars'] ?? 0),
            'moneda' => $currency,
            'cotizacion' => $currency === 'ARS' ? null : (float) ($row['cotizacion'] ?? 1),
            'importe_ars' => (float) ($row['importe_ars'] ?? 0),
        ];
    }

    /** @param list<array<string,mixed>> $assignments */
    private static function assignmentLabel(array $assignments, string $role): ?string
    {
        foreach ($assignments as $assignment) {
            if (($assignment['rol'] ?? null) === $role) {
                return $assignment['label'] ?? null;
            }
        }

        return null;
    }

    private static function cashType(string $type): string
    {
        return match ($type) {
            'CAJA_INICIAL' => 'Caja inicial',
            'ANTICIPO' => 'Anticipo',
            'RETIRO' => 'Retiro',
            TripCashMovement::DEVOLUCION => 'Devolución',
            default => $type === '' ? 'Sin dato' : $type,
        };
    }

    /**
     * @param array<string,mixed> $totals
     * @param list<array<string,mixed>> $legs
     */
    private static function pendingReason(array $totals, array $legs): string
    {
        if (! empty($totals['multiple_drivers'])) {
            return 'Hay más de un chofer asignado. La liquidación se calcula cuando hay un chofer responsable.';
        }
        if (($totals['driver_percentage'] ?? null) === null) {
            return 'El chofer no tiene un porcentaje de liquidación definido.';
        }
        if (empty($totals['has_legs'])) {
            return 'Todavía no hay tramos cargados.';
        }

        // Solo se nombran los tramos sin base de liquidación: que falte el
        // flete de facturación no es asunto de esta vista.
        $missing = array_map(
            static fn (array $leg): int => (int) ($leg['orden'] ?? 0),
            array_filter($legs, static fn (array $leg): bool => ($leg['flete_liquidacion_ars'] ?? null) === null),
        );

        return 'Faltan bases de liquidación en los tramos ' . implode(', ', $missing) . '.';
    }
}
