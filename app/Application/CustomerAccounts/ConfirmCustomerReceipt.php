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
use App\Domain\CustomerAccounts\Money;
use App\Domain\CustomerAccounts\ReceiptImputation;
use DomainException;

/**
 * Confirma un recibo en borrador: valida una última vez lo imputado contra los
 * documentos y contra lo que realmente se acredita, reserva el número
 * correlativo y contabiliza el Haber de los medios que correspondan.
 */
final class ConfirmCustomerReceipt
{
    public function __construct(
        private readonly CustomerReceiptRepository $receipts,
        private readonly CustomerAccountsUnitOfWork $unitOfWork,
        private readonly CustomerAccountsClock $clock,
        private readonly AuditTrail $audit,
    ) {
    }

    public function execute(ActorContext $actor, int $receiptId): void
    {
        $companyId = CustomerReceiptAccess::confirm($actor);
        $branchIds = CustomerReceiptAccess::branches($actor);
        $now = $this->clock->now();

        $this->unitOfWork->transactional(function () use ($actor, $receiptId, $companyId, $branchIds, $now): void {
            $receipt = $this->receipts->findForUpdate($companyId, $receiptId);
            if ($receipt === null || ! $this->inBranches($receipt, $branchIds)) {
                throw new DomainException('El recibo no existe o no está autorizado.');
            }
            CustomerReceipt::assertConfirmable((string) $receipt['estado']);

            $instruments = $this->receipts->instrumentsForUpdate($companyId, $receiptId);
            $imputations = $this->imputations($this->receipts->imputationsForUpdate($companyId, $receiptId));
            $creditable = 0;
            foreach ($instruments as $instrument) {
                if ((int) $instrument['acredita_al_confirmar'] === 1 && (string) $instrument['estado_acreditacion'] === 'PENDIENTE') {
                    $creditable += Money::toMinor((float) $instrument['importe_ars']);
                }
            }
            $imputed = 0;
            foreach ($imputations as $imputation) {
                $imputed += $imputation->amountMinor();
            }
            CustomerReceipt::assertImputationsCovered($imputed, $creditable);
            ReceiptImputationGuard::validate($this->receipts, $companyId, (int) $receipt['cliente_id'], $imputations, $creditable);

            $series = (string) $receipt['serie'];
            $number = $this->receipts->nextNumber($companyId, (int) $receipt['sucursal_id'], $series);
            $this->receipts->confirm($companyId, $receiptId, $number, $now, $actor->userId());

            CustomerAccountAudit::record(
                $this->audit,
                $actor,
                $companyId,
                (int) $receipt['sucursal_id'],
                AuditAction::CLIENTE_RECIBO_CONFIRMADO,
                'cliente_recibos',
                $receiptId,
                ['estado' => CustomerReceipt::DRAFT],
                [
                    'estado' => CustomerReceipt::CONFIRMED,
                    'serie' => $series,
                    'numero' => $number,
                    'numero_visible' => $series . '-' . str_pad((string) $number, 6, '0', STR_PAD_LEFT),
                    'total_ars' => (float) $receipt['total_ars'],
                    'acreditado_ars' => Money::toDecimal($creditable),
                    'imputado_ars' => Money::toDecimal($imputed),
                ],
            );
        });
    }

    /** @param list<array<string,mixed>> $rows @return list<ReceiptImputation> */
    private function imputations(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            if ((string) $row['estado'] !== 'VIGENTE') {
                continue;
            }
            $result[] = new ReceiptImputation((int) $row['cargo_id'], Money::toMinor((float) $row['importe_ars']));
        }

        return $result;
    }

    private function inBranches(array $receipt, ?array $branchIds): bool
    {
        return $branchIds === null || in_array((int) $receipt['sucursal_id'], $branchIds, true);
    }
}
