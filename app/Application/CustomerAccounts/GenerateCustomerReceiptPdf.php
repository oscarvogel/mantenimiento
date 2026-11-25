<?php

declare(strict_types=1);

namespace App\Application\CustomerAccounts;

use App\Application\CustomerAccounts\Port\CustomerReceiptPdfRenderer;
use App\Application\CustomerAccounts\Port\CustomerReceiptReadModel;
use App\Application\Identity\ActorContext;
use DomainException;

/** Genera el comprobante imprimible del recibo, confirmado o anulado (#536). */
final class GenerateCustomerReceiptPdf
{
    public function __construct(
        private readonly CustomerReceiptReadModel $readModel,
        private readonly CustomerReceiptPdfRenderer $renderer,
    ) {
    }

    /** @return array{filename:string,content:string} */
    public function execute(ActorContext $actor, int $receiptId): array
    {
        $companyId = CustomerReceiptAccess::print($actor);
        $branchIds = CustomerReceiptAccess::branches($actor);
        $detail = $this->readModel->detail($companyId, $branchIds, $receiptId);
        if ($detail === null) {
            throw new DomainException('El recibo no existe o no pertenece a la empresa.');
        }
        $number = (string) ($detail['receipt']['numero_visible'] ?? 'borrador');

        return [
            'filename' => 'recibo-' . preg_replace('/[^A-Za-z0-9\-]/', '-', $number) . '.pdf',
            'content' => $this->renderer->render($detail),
        ];
    }
}
