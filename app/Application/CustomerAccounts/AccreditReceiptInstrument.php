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
 * Acredita un medio que quedó pendiente al confirmar el recibo: un cheque que
 * se hizo efectivo o una transferencia que finalmente se verificó (#536).
 *
 * El asiento usa la clave única del medio, así que un reintento no puede
 * generar un segundo Haber. La acreditación no imputa sola: el crédito queda
 * disponible como anticipo hasta que se aplique a documentos.
 */
final class AccreditReceiptInstrument
{
    public function __construct(
        private readonly CustomerReceiptRepository $receipts,
        private readonly CustomerAccountsUnitOfWork $unitOfWork,
        private readonly CustomerAccountsClock $clock,
        private readonly AuditTrail $audit,
    ) {
    }

    public function execute(ActorContext $actor, int $receiptId, int $instrumentId): void
    {
        $companyId = CustomerReceiptAccess::confirm($actor);
        $branchIds = CustomerReceiptAccess::branches($actor);
        $now = $this->clock->now();

        $this->unitOfWork->transactional(function () use ($actor, $receiptId, $instrumentId, $companyId, $branchIds, $now): void {
            $receipt = $this->receipts->findForUpdate($companyId, $receiptId);
            if ($receipt === null || ($branchIds !== null && ! in_array((int) $receipt['sucursal_id'], $branchIds, true))) {
                throw new DomainException('El recibo no existe o no está autorizado.');
            }
            if ((string) $receipt['estado'] !== CustomerReceipt::CONFIRMED) {
                throw new DomainException('Solo se acreditan medios de un recibo confirmado y vigente.');
            }
            $instrument = $this->receipts->instrumentForUpdate($companyId, $receiptId, $instrumentId);
            if ($instrument === null) {
                throw new DomainException('El medio de pago no pertenece a este recibo.');
            }
            $state = (string) $instrument['estado_acreditacion'];
            if ($state === 'ACREDITADO') {
                throw new DomainException('Ese medio de pago ya está acreditado en la cuenta corriente.');
            }
            if ($state !== 'PENDIENTE') {
                throw new DomainException('El medio de pago está anulado y no se puede acreditar.');
            }

            $this->receipts->accreditInstrument($companyId, $receiptId, $instrumentId, $now, $actor->userId());
            CustomerAccountAudit::record(
                $this->audit,
                $actor,
                $companyId,
                (int) $receipt['sucursal_id'],
                AuditAction::CLIENTE_RECIBO_MEDIO_ACREDITADO,
                'cliente_recibo_medios',
                $instrumentId,
                ['estado_acreditacion' => 'PENDIENTE'],
                [
                    'estado_acreditacion' => 'ACREDITADO',
                    'recibo_id' => $receiptId,
                    'medio' => (string) $instrument['medio'],
                    'importe_ars' => (float) $instrument['importe_ars'],
                    'acreditado_at' => $now->format('Y-m-d H:i:s'),
                ],
            );
        });
    }
}
