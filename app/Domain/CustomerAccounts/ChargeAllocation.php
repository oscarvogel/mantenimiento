<?php

declare(strict_types=1);

namespace App\Domain\CustomerAccounts;

use DomainException;

/**
 * Reparto del valor histórico de un documento entre los cobros que lo cancelan
 * (#588).
 *
 * El documento tiene un valor histórico ya fijado en su momento: el equivalente
 * en pesos de la deuda original a la cotización congelada. Cuando el documento
 * se paga en partes, cada parte se lleva una parte de ese valor, y la última
 * se lleva el residual exacto.
 *
 * Repartir el valor del documento (y no recalcular cada parte multiplicando
 * por la cotización) es lo que garantiza que las partes sumen exactamente el
 * documento: cuando la deuda en su moneda original llega a cero, el ledger en
 * pesos de esa moneda llega a cero, sin centavos residuales por redondeo.
 */
final readonly class ChargeAllocation
{
    public function __construct(
        private int $chargeOriginalMinor,
        private int $chargeArsMinor,
        private int $allocatedOriginalMinor,
        private int $allocatedArsMinor,
    ) {
        if ($chargeOriginalMinor <= 0) {
            throw new DomainException('El documento no tiene importe para repartir.');
        }
        if ($chargeArsMinor < 0) {
            throw new DomainException('El valor histórico del documento no puede ser negativo.');
        }
        if ($allocatedOriginalMinor < 0 || $allocatedOriginalMinor > $chargeOriginalMinor) {
            throw new DomainException('Lo cobrado del documento no es coherente con su importe.');
        }
        if ($allocatedArsMinor < 0 || $allocatedArsMinor > $chargeArsMinor) {
            throw new DomainException('El valor histórico repartido no es coherente con el documento.');
        }
    }

    /** Importe del documento en su moneda original, en centavos. */
    public function chargeOriginalMinor(): int
    {
        return $this->chargeOriginalMinor;
    }

    /** Valor histórico del documento, en centavos de pesos. */
    public function chargeArsMinor(): int
    {
        return $this->chargeArsMinor;
    }

    /** Saldo pendiente del documento en su moneda original, en centavos. */
    public function pendingOriginalMinor(): int
    {
        return $this->chargeOriginalMinor - $this->allocatedOriginalMinor;
    }

    /**
     * Parte del valor histórico del documento que le corresponde a lo que se
     * imputa, en centavos de pesos.
     *
     * Si la imputación cierra el documento, se lleva el residual exacto.
     */
    public function allocate(int $imputedMinor): int
    {
        if ($imputedMinor <= 0) {
            throw new DomainException('El importe imputado debe ser mayor que cero.');
        }
        $remainingOriginal = $this->pendingOriginalMinor();
        $remainingArs = $this->chargeArsMinor - $this->allocatedArsMinor;
        if ($remainingOriginal <= 0) {
            return 0;
        }
        if ($imputedMinor >= $remainingOriginal) {
            return $remainingArs;
        }

        $share = intdiv($this->chargeArsMinor * $imputedMinor + intdiv($this->chargeOriginalMinor, 2), $this->chargeOriginalMinor);

        return max(0, min($share, $remainingArs));
    }
}
