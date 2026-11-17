<?php

declare(strict_types=1);

namespace App\Domain\Trips;

use DomainException;

/**
 * Cómo se acordó con el chofer la base de liquidación de un tramo.
 *
 * Hay dos acuerdos posibles, y el operador elige uno por tramo:
 *
 * - POR_TONELADA: toneladas × precio por tonelada. El importe sale de la
 *   multiplicación; no se carga a mano.
 * - MONTO_ACORDADO: un importe global pactado. No pide toneladas ni precio, y
 *   el sistema nunca deduce cantidades ni precios unitarios para "justificar"
 *   el monto.
 *
 * La base no es lo que cobra el chofer: sobre ella se aplica el porcentaje y
 * después los haberes, gastos, anticipos y devoluciones. Tampoco tiene relación
 * con cómo se factura el tramo al cliente: un tramo puede facturarse por
 * tonelada y liquidarse con un monto acordado, o al revés.
 *
 * La moneda y la cotización viven en {@see TripLegAmount}: este objeto solo
 * describe el origen del importe en la moneda de liquidación.
 */
final readonly class TripLegLiquidationBasis
{
    public const POR_TONELADA = 'POR_TONELADA';
    public const MONTO_ACORDADO = 'MONTO_ACORDADO';

    public const MODES = [self::POR_TONELADA, self::MONTO_ACORDADO];

    private function __construct(
        private string $mode,
        private ?float $tonnes,
        private ?float $unitPrice,
        private ?float $agreedAmount,
    ) {
    }

    /**
     * Liquidación por toneladas. Las dos partes son opcionales mientras el
     * viaje está abierto, pero si falta alguna no hay importe: el cierre lo
     * reclama igual que a un flete vacío.
     */
    public static function perTonne(?float $tonnes, ?float $unitPrice): self
    {
        if ($tonnes !== null && $tonnes < 0) {
            throw new DomainException('Las toneladas de la liquidación no pueden ser negativas.');
        }
        if ($unitPrice !== null && $unitPrice < 0) {
            throw new DomainException('El precio por tonelada de la liquidación no puede ser negativo.');
        }

        return new self(
            self::POR_TONELADA,
            $tonnes === null ? null : round($tonnes, 3),
            $unitPrice === null ? null : round($unitPrice, 4),
            null,
        );
    }

    public static function agreedAmount(?float $amount): self
    {
        if ($amount !== null && $amount < 0) {
            throw new DomainException('El monto acordado con el chofer no puede ser negativo.');
        }

        return new self(self::MONTO_ACORDADO, null, null, $amount === null ? null : round($amount, 4));
    }

    public static function fromMode(string $mode, ?float $tonnes, ?float $unitPrice, ?float $agreedAmount): self
    {
        return match (strtoupper(trim($mode))) {
            self::POR_TONELADA => self::perTonne($tonnes, $unitPrice),
            self::MONTO_ACORDADO => self::agreedAmount($agreedAmount),
            default => throw new DomainException('Elegí cómo se liquida el tramo al chofer: por toneladas o con un monto acordado.'),
        };
    }

    public function mode(): string
    {
        return $this->mode;
    }

    public function isPerTonne(): bool
    {
        return $this->mode === self::POR_TONELADA;
    }

    public function label(): string
    {
        return self::labelFor($this->mode);
    }

    public static function labelFor(string $mode): string
    {
        return $mode === self::POR_TONELADA ? 'Por toneladas' : 'Monto acordado';
    }

    public function tonnes(): ?float
    {
        return $this->tonnes;
    }

    public function unitPrice(): ?float
    {
        return $this->unitPrice;
    }

    /** Importe de la base en la moneda de liquidación; null si falta un dato. */
    public function amount(): ?float
    {
        if ($this->mode === self::MONTO_ACORDADO) {
            return $this->agreedAmount;
        }
        if ($this->tonnes === null || $this->unitPrice === null) {
            return null;
        }

        return round($this->tonnes * $this->unitPrice, 4);
    }
}
