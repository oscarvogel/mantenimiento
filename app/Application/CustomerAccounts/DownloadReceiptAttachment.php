<?php

declare(strict_types=1);

namespace App\Application\CustomerAccounts;

use App\Application\CustomerAccounts\Port\CustomerReceiptRepository;
use App\Application\CustomerAccounts\Port\ReceiptAttachmentStorage;
use App\Application\Identity\ActorContext;
use DomainException;

/** Descarga un comprobante privado del recibo, siempre dentro de la empresa (#536). */
final class DownloadReceiptAttachment
{
    public function __construct(
        private readonly CustomerReceiptRepository $receipts,
        private readonly ReceiptAttachmentStorage $storage,
    ) {
    }

    /** @return array{name:string,mime_type:string,content:string} */
    public function execute(ActorContext $actor, int $receiptId, int $attachmentId): array
    {
        $companyId = CustomerReceiptAccess::view($actor);
        $branchIds = CustomerReceiptAccess::branches($actor);
        $scope = $this->receipts->receiptScope($companyId, $receiptId);
        if ($scope === null || ($branchIds !== null && ! in_array($scope['sucursal_id'], $branchIds, true))) {
            throw new DomainException('El recibo no existe o no está autorizado.');
        }
        $attachment = $this->receipts->findAttachment($companyId, $receiptId, $attachmentId);
        if ($attachment === null) {
            throw new DomainException('El comprobante no existe o no pertenece a este recibo.');
        }

        return [
            'name' => (string) $attachment['nombre_original'],
            'mime_type' => (string) $attachment['mime_type'],
            'content' => $this->storage->read((string) $attachment['ruta_privada']),
        ];
    }
}
