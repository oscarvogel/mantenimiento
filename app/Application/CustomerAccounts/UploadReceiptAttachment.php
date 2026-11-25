<?php

declare(strict_types=1);

namespace App\Application\CustomerAccounts;

use App\Application\Audit\AuditAction;
use App\Application\Audit\Port\AuditTrail;
use App\Application\CustomerAccounts\Port\CustomerReceiptRepository;
use App\Application\CustomerAccounts\Port\CustomerAccountsClock;
use App\Application\CustomerAccounts\Port\ReceiptAttachmentStorage;
use App\Application\CustomerAccounts\Port\ReceiptFileInspector;
use App\Application\Identity\ActorContext;
use App\Domain\CustomerAccounts\CustomerReceipt;
use App\Domain\CustomerAccounts\ReceiptAttachmentRules;
use DomainException;
use Throwable;

/**
 * Adjunta un comprobante privado al recibo: la transferencia, el cheque o el
 * respaldo de la cobranza (#536). El tipo se valida por contenido real y el
 * archivo se guarda fuera del webroot.
 */
final class UploadReceiptAttachment
{
    public function __construct(
        private readonly CustomerReceiptRepository $receipts,
        private readonly ReceiptFileInspector $inspector,
        private readonly ReceiptAttachmentStorage $storage,
        private readonly CustomerAccountsClock $clock,
        private readonly AuditTrail $audit,
    ) {
    }

    public function execute(
        ActorContext $actor,
        int $receiptId,
        ?int $instrumentId,
        string $temporaryPath,
        string $originalName,
        ?string $description,
    ): int {
        $companyId = CustomerReceiptAccess::register($actor);
        $branchIds = CustomerReceiptAccess::branches($actor);
        $scope = $this->receipts->receiptScope($companyId, $receiptId);
        if ($scope === null || ($branchIds !== null && ! in_array($scope['sucursal_id'], $branchIds, true))) {
            throw new DomainException('El recibo no existe o no está autorizado.');
        }
        if ($scope['estado'] === CustomerReceipt::CANCELLED) {
            throw new DomainException('El recibo está anulado: no se le pueden agregar comprobantes.');
        }
        if ($instrumentId !== null && ! $this->receipts->instrumentBelongsToReceipt($companyId, $receiptId, $instrumentId)) {
            throw new DomainException('El medio de pago no pertenece a este recibo.');
        }

        $inspected = $this->inspector->inspect($temporaryPath);
        ReceiptAttachmentRules::assertUpload($originalName, $inspected['mime_type'], $inspected['size']);
        $extension = ReceiptAttachmentRules::canonicalExtension($inspected['mime_type']);
        $stored = $this->storage->store($temporaryPath, $companyId, $extension);

        try {
            $attachmentId = $this->receipts->addAttachment(
                $companyId,
                $receiptId,
                $instrumentId,
                mb_substr(trim($originalName), 0, 255),
                $stored['stored_name'],
                $stored['private_path'],
                $inspected['mime_type'],
                $inspected['size'],
                $description === null ? null : mb_substr(trim($description), 0, 255),
                $actor->userId(),
                $this->clock->now(),
            );
            CustomerAccountAudit::record(
                $this->audit,
                $actor,
                $companyId,
                $scope['sucursal_id'],
                AuditAction::CLIENTE_RECIBO_ADJUNTO_AGREGADO,
                'cliente_recibo_adjuntos',
                $attachmentId,
                null,
                [
                    'recibo_id' => $receiptId,
                    'medio_id' => $instrumentId,
                    'nombre_original' => mb_substr(trim($originalName), 0, 255),
                    'mime_type' => $inspected['mime_type'],
                    'tamanio' => $inspected['size'],
                ],
            );

            return $attachmentId;
        } catch (Throwable $exception) {
            try {
                $this->storage->delete($stored['private_path']);
            } catch (Throwable) {
                // Se conserva el error de persistencia; el archivo huérfano se puede limpiar después.
            }
            throw $exception;
        }
    }
}
