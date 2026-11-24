<?php

declare(strict_types=1);

namespace App\Domain\Trips;

/**
 * Porcentaje de liquidación que se aplica al flete de liquidación del viaje.
 *
 * El porcentaje tiene tres orígenes posibles, y el orden importa: gana el más
 * específico.
 *
 * 1. **VIAJE**: el porcentaje quedó guardado en la asignación del chofer
 *    (`viaje_operativo_asignaciones.porcentaje_liquidacion`). Es una copia
 *    editable del acuerdo y es la que manda.
 * 2. **MAESTRO**: la fila vigente del maestro de porcentajes de liquidación
 *    del chofer a la fecha de salida del viaje.
 * 3. **EMPRESA**: el porcentaje por defecto de la empresa. Es el respaldo
 *    para los choferes que todavía no tienen fila en el maestro (por ejemplo,
 *    los que se dieron de alta después de la última carga del maestro).
 *
 * Si ninguno de los tres existe, el porcentaje no está definido: la comisión
 * y el margen quedan sin dato en lugar de inventarse un número.
 */
final readonly class DriverLiquidationPercentage
{
    public const SOURCE_TRIP = 'VIAJE';
    public const SOURCE_MASTER = 'MAESTRO';
    public const SOURCE_COMPANY_DEFAULT = 'EMPRESA';

    private function __construct(
        private ?float $value,
        private ?string $source,
    ) {
    }

    /**
     * Resuelve el porcentaje efectivo y deja constancia de su origen.
     */
    public static function resolve(?float $tripPercentage, ?float $masterPercentage, ?float $companyDefault): self
    {
        if ($tripPercentage !== null) {
            return new self(round($tripPercentage, 2), self::SOURCE_TRIP);
        }
        if ($masterPercentage !== null) {
            return new self(round($masterPercentage, 2), self::SOURCE_MASTER);
        }
        if ($companyDefault !== null) {
            return new self(round($companyDefault, 2), self::SOURCE_COMPANY_DEFAULT);
        }

        return new self(null, null);
    }

    /** Porcentaje efectivo; null cuando ningún origen lo define. */
    public function value(): ?float
    {
        return $this->value;
    }

    /** Origen del porcentaje: VIAJE, MAESTRO o EMPRESA; null si no está definido. */
    public function source(): ?string
    {
        return $this->source;
    }

    public function isResolved(): bool
    {
        return $this->value !== null;
    }

    /** True cuando el valor vino del porcentaje por defecto de la empresa. */
    public function comesFromCompanyDefault(): bool
    {
        return $this->source === self::SOURCE_COMPANY_DEFAULT;
    }

    public static function labelFor(?string $source): ?string
    {
        return match ($source) {
            self::SOURCE_TRIP => 'del viaje',
            self::SOURCE_MASTER => 'del maestro de porcentajes',
            self::SOURCE_COMPANY_DEFAULT => 'del porcentaje por defecto de la empresa',
            default => null,
        };
    }
}
