<?php

declare(strict_types=1);

namespace App\Application\CustomerAccounts;

use App\Application\CustomerAccounts\Port\CustomerReceiptRepository;
use App\Domain\CustomerAccounts\CustomerCharge;
use App\Domain\CustomerAccounts\Money;
use DomainException;

/**
 * Valida imputaciones contra los documentos reales del cliente.
 *
 * Los cargos se bloquean antes de calcular cuánto queda pendiente, de modo que
 * dos recibos concurrentes no puedan imputar más de lo que debe el documento.
 */
final class ReceiptImputationGuard
{
    /** @param list<\App\Domain\CustomerAccounts\ReceiptImputation> $imputations */
    public static function validate(
        CustomerReceiptRepository $receipts,
        int $companyId,
        int $customerId,
        array $imputations,
        int $availableMinor,
    ): void {
        if ($imputations === []) {
            return;
        }
        $totals = ReceiptInstrumentFactory::totalsByCharge($imputations);
        $charged = Money::add(...array_values($totals));
        if ($charged > $availableMinor) {
            throw new DomainException('La suma imputada supera el importe disponible del recibo.');
        }

        $charges = $receipts->lockCharges($companyId, $customerId, array_keys($totals));
        foreach ($totals as $chargeId => $amountMinor) {
            $charge = $charges[$chargeId] ?? null;
            if ($charge === null) {
                throw new DomainException('El documento #' . $chargeId . ' no existe o no pertenece al cliente.');
            }
            if ((string) $charge['estado'] !== 'VIGENTE' || (string) $charge['sentido'] !== CustomerCharge::DEBE) {
                throw new DomainException('El documento #' . $chargeId . ' no está vigente como deuda del cliente.');
            }
            $pendingMinor = Money::toMinor((float) $charge['pendiente_ars']);
            if ($amountMinor > $pendingMinor) {
                throw new DomainException(
                    'El documento #' . $chargeId . ' tiene ' . Money::format($pendingMinor)
                    . ' pendiente y se intentó imputar ' . Money::format($amountMinor) . '.',
                );
            }
        }
    }
}
