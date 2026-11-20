<?php

declare(strict_types=1);

namespace App\Domain\CustomerAccounts;

use DomainException;

/**
 * Cierre comercial sin deuda: el tramo no se le cobra al cliente (#530).
 *
 * No genera movimientos en la cuenta corriente. Exige un motivo para que la
 * decisión quede justificada, y es distinto de "no facturado": un tramo sin
 * factura puede ser perfectamente cobrable por memo o por cierre interno.
 */
final readonly class NonCollectibleClosure
{
    private string $reason;

    public function __construct(private BillableLeg $leg, string $reason)
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw new DomainException('Explicá por qué el tramo no se cobra.');
        }
        if (mb_strlen($reason) > 500) {
            throw new DomainException('El motivo admite hasta 500 caracteres.');
        }
        if (! $leg->tripFinished()) {
            throw new DomainException('Solo se cierran comercialmente tramos de viajes finalizados.');
        }

        $this->reason = $reason;
    }

    public function leg(): BillableLeg
    {
        return $this->leg;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function activeKey(): string
    {
        return 'TRAMO:' . $this->leg->legId();
    }
}
