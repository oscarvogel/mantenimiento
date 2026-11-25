<?php

declare(strict_types=1);

namespace App\Application\CustomerAccounts;

use App\Application\Audit\AuditAction;
use App\Application\Audit\Port\AuditTrail;
use App\Application\CustomerAccounts\Port\CustomerReceiptRepository;
use App\Application\CustomerAccounts\Port\CustomerAccountsClock;
use App\Application\CustomerAccounts\Port\CustomerAccountsUnitOfWork;
use App\Application\CustomerAccounts\Port\ReceiptAttachmentStorage;
use App\Application\Identity\ActorContext;
use App\Domain\CustomerAccounts\CustomerReceipt;
use DomainException;
use Throwable;

/**
 * Descarta un recibo en borrador. Un recibo confirmado nunca se borra: se anula
 * con contraasientos. Los comprobantes privados del borrador se eliminan del
 * almacenamiento después de confirmar la baja en la base.
 */
final class DiscardCustomerReceiptDraft
{
    public function __construct(
        private readonly CustomerReceiptRepository $receipts,
        private readonly ReceiptAttachmentStorage $storage,
        private readonly CustomerAccountsUnitOfWork $unitOfWork,
        private readonly CustomerAccountsClock $clock,
        private readonly AuditTrail $audit,
    ) {
    }

    public function execute(ActorContext $actor, int $receiptId): void
    {
        $companyId = CustomerReceiptAccess::register($actor);
        $branchIds = CustomerReceiptAccess::branches($actor);
        $now = $this->clock->now();
        $paths = [];

        $this->unitOfWork->transactional(function () use ($actor, $receiptId, $companyId, $branchIds, $now, &$paths): void {
            $receipt = $this->receipts->findForUpdate($companyId, $receiptId);
            if ($receipt === null || ($branchIds !== null && ! in_array((int) $receipt['sucursal_id'], $branchIds, true))) {
                throw new DomainException('El recibo no existe o no está autorizado.');
            }
            if ((string) $receipt['estado'] !== CustomerReceipt::DRAFT) {
                throw new DomainException('Solo se descarta un recibo en borrador. Un recibo confirmado se anula.');
            }
            $paths = $this->receipts->attachmentPaths($companyId, $receiptId);
            $this->receipts->discardDraft($companyId, $receiptId);
            CustomerAccountAudit::record(
                $this->audit,
                $actor,
                $companyId,
                (int) $receipt['sucursal_id'],
                AuditAction::CLIENTE_RECIBO_BORRADOR_DESCARTADO,
                'cliente_recibos',
                $receiptId,
                ['estado' => CustomerReceipt::DRAFT, 'total_ars' => (float) $receipt['total_ars']],
                ['estado' => 'DESCARTADO'],
            );
        });

        foreach ($paths as $path) {
            try {
                $this->storage->delete($path);
            } catch (Throwable $exception) {
                log_message('error', 'No se pudo borrar un comprobante de un recibo descartado: {message}', ['message' => $exception->getMessage()]);
            }
        }
    }
}
