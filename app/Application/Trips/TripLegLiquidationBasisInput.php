<?php

declare(strict_types=1);

namespace App\Application\Trips;

use App\Domain\Trips\TripLegLiquidationBasis;
use DomainException;

/**
 * Lee del formulario de un tramo cómo se acordó la liquidación con el chofer.
 *
 * Los formularios mandan `modo_liquidacion` de forma explícita. Si no llega
 * (integraciones o formularios anteriores a #533), el tramo se interpreta como
 * se cargó siempre: `flete_liquidacion` es un monto acordado.
 */
final class TripLegLiquidationBasisInput
{
    /** @param array<string,mixed> $row */
    public static function fromInput(array $row, ?float $liquidationAmount): TripLegLiquidationBasis
    {
        $mode = strtoupper(trim((string) ($row['modo_liquidacion'] ?? '')));
        if ($mode === '') {
            $mode = TripLegLiquidationBasis::MONTO_ACORDADO;
        }

        if ($mode === TripLegLiquidationBasis::POR_TONELADA) {
            $tonnes = self::optionalAmount($row['toneladas_liquidacion'] ?? null, 'Las toneladas de la liquidación');
            $unitPrice = self::optionalAmount($row['precio_unitario_liquidacion'] ?? null, 'El precio por tonelada de la liquidación');
            if (($tonnes === null) !== ($unitPrice === null)) {
                throw new DomainException('Para liquidar por toneladas cargá las toneladas y el precio por tonelada del chofer.');
            }

            return TripLegLiquidationBasis::perTonne($tonnes, $unitPrice);
        }

        return TripLegLiquidationBasis::fromMode($mode, null, null, $liquidationAmount);
    }

    private static function optionalAmount(mixed $value, string $label): ?float
    {
        $raw = str_replace(',', '.', trim((string) ($value ?? '')));
        if ($raw === '') {
            return null;
        }
        if (! is_numeric($raw) || (float) $raw < 0) {
            throw new DomainException($label . ' no puede ser negativo.');
        }

        return (float) $raw;
    }
}
