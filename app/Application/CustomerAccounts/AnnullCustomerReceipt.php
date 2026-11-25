<?php

declare(strict_types=1);

namespace App\Application\CustomerAccounts;

use App\Application\Audit\AuditAction;
use App\Application\Audit\Port\AuditTrail;
use App\Application\CustomerAccounts\Port\CustomerReceiptRepository;
use App\Application\CustomerAccounts\Port\CustomerAccountsClock;
use App\Application\CustomerAccounts\Port\CustomerAccountsUnitOfWork;
use App\Application\Identity\ActorContext;
use App\Domain\CustomerAccounts\CustomerReceipt;
use DomainException;

/**
 * Anula un recibo confirmado con contraasientos por lo efectivamente
 * contabilizado (#536).
 *
 * No se puede anular si un cheque ya fue depositado, endosado o acreditado, o
 * si una transferencia fue conciliada: eso pertenece a Tesorería (#537/#538) y
 * anularlo dejaría dos circuitos inconsistentes. En ese caso se informa el
 * bloqueo en lugar de forzar el asiento.
 */
final class AnnullCustomerReceipt
{
    private const TREASURY_LOCKED_CHEQUE_STATES = ['DEPOSITADO', 'ENDOSADO', 'ACREDITADO', 'RECHAZADO'];

    public function __construct(
        private readonly CustomerReceiptRepository $receipts,
        private readonly CustomerAccountsUnitOfWork $unitOfWork,
        private readonly CustomerAccountsClock $clock,
        private readonly AuditTrail $audit,
    ) {
    }

    public function execute(ActorContext $actor, int $receiptId, string $reason): void
    {
        $companyId = CustomerReceiptAccess::cancel($actor);
        $branchIds = CustomerReceiptAccess::branches($actor);
        $now = $this->clock->now();
        $reason = trim($reason);

        $this->unitOfWork->transactional(function () use ($actor, $receiptId, $companyId, $branchIds, $now, $reason): void {
            $receipt = $this->receipts->findForUpdate($companyId, $receiptId);
            if ($receipt === null || ($branchIds !== null && ! in_array((int) $receipt['sucursal_id'], $branchIds, true))) {
                throw new DomainException('El recibo no existe o no está autorizado.');
            }
            $reason = CustomerReceipt::assertAnnulable((string) $receipt['estado'], $reason, $actor->userId());

            foreach ($this->receipts->instrumentsForUpdate($companyId, $receiptId) as $instrument) {
                $chequeState = strtoupper((string) ($instrument['cheque_estado'] ?? ''));
                if ($chequeState !== '' && in_array($chequeState, self::TREASURY_LOCKED_CHEQUE_STATES, true)) {
                    throw new DomainException(
                        'El cheque ' . (string) ($instrument['cheque_numero'] ?? '') . ' ya está ' . strtolower($chequeState)
                        . ' en tesorería. Para anular el recibo, primero resolvé el cheque desde su cartera.',
                    );
                }
                if (($instrument['conciliado_at'] ?? null) !== null) {
                    throw new DomainException('La transferencia ya fue conciliada en tesorería y no se puede anular el recibo.');
                }
            }

            $this->receipts->annul($companyId, $receiptId, $reason, $now, $actor->userId());
            CustomerAccountAudit::record(
                $this->audit,
                $actor,
                $companyId,
                (int) $receipt['sucursal_id'],
                AuditAction::CLIENTE_RECIBO_ANULADO,
                'cliente_recibos',
                $receiptId,
                [
                    'estado' => (string) $receipt['estado'],
                    'numero_visible' => (string) ($receipt['numero_visible'] ?? ''),
                    'total_ars' => (float) $receipt['total_ars'],
                    'acreditado_ars' => (float) $receipt['total_acreditado_ars'],
                ],
                [
                    'estado' => CustomerReceipt::CANCELLED,
                    'motivo_anulacion' => $reason,
                    'anulado_at' => $now->format('Y-m-d H:i:s'),
                ],
                $reason,
            );
        });
    }
}
