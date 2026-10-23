<?php

declare(strict_types=1);

namespace App\Application\TripImports;

use App\Application\Identity\ActorContext;
use App\Application\TripImports\Port\InvoiceReviewReadModel;
use App\Application\TripImports\Port\TripImportStore;
use DomainException;

final class ReviewTripInvoices
{
    public function __construct(
        private readonly InvoiceReviewReadModel $readModel,
        private readonly TripImportStore $store,
    ) {
    }

    /** @return array<string,mixed> */
    public function page(ActorContext $actor, int $importId, ?string $status, int $page): array
    {
        $this->authorize($actor);
        $import = $this->store->import($importId, $actor->userId());
        $companyId = (int) $import['empresa_id'];
        $result = $this->readModel->review($companyId, $importId, $status, max(1, $page), 20);

        return [
            'import' => [
                'id' => $importId,
                'company_id' => $companyId,
                'company' => $this->store->company($companyId)['name'],
                'estado' => $import['estado'] ?? null,
            ],
            'status' => $status,
            'items' => $result['items'],
            'pagination' => [
                'page' => $result['page'],
                'pages' => $result['pages'],
                'total' => $result['total'],
                'perPage' => $result['perPage'],
            ],
            'links' => [
                'back' => base_url('superadmin/viajes/importaciones/' . $importId),
                'assign' => base_url('superadmin/viajes/importaciones/' . $importId . '/facturas'),
            ],
        ];
    }

    public function assign(ActorContext $actor, int $importId, int $invoiceId, int $tripId): void
    {
        $this->authorize($actor);
        $import = $this->store->import($importId, $actor->userId());
        $companyId = (int) $import['empresa_id'];
        $invoice = $this->readModel->invoice($companyId, $invoiceId);
        if ($invoice === null) {
            throw new DomainException('La factura no existe en la empresa de la importacion.');
        }
        if ($invoice['importacion_id'] !== null && (int) $invoice['importacion_id'] !== $importId) {
            throw new DomainException('La factura pertenece a otra importacion.');
        }
        $trip = $this->readModel->trip($companyId, $tripId);
        if ($trip === null) {
            throw new DomainException('El viaje seleccionado no existe en la empresa.');
        }

        $now = date('Y-m-d H:i:s');
        $this->store->linkInvoice([
            'factura_id' => $invoiceId,
            'viaje_id' => $tripId,
            'empresa_id' => $companyId,
            'sucursal_id' => $trip['branch_id'],
            'estado' => 'CONFIRMADO',
            'confianza' => 'MANUAL',
            'puntaje' => null,
            'metodo' => 'REVISION_MANUAL',
            'motivo' => null,
            'created_at' => $now,
            'updated_at' => $now,
            'created_by' => $actor->userId(),
            'updated_by' => $actor->userId(),
        ]);
    }

    public function unassign(ActorContext $actor, int $importId, int $invoiceId): void
    {
        $this->authorize($actor);
        $import = $this->store->import($importId, $actor->userId());
        $companyId = (int) $import['empresa_id'];
        if ($this->readModel->invoice($companyId, $invoiceId) === null) {
            throw new DomainException('La factura no existe en la empresa de la importacion.');
        }
        $this->store->unlinkInvoice($invoiceId);
    }

    private function authorize(ActorContext $actor): void
    {
        if (! $actor->isSuperAdmin()) {
            throw new DomainException('La revision de facturas requiere acceso de Superadministrador.');
        }
    }
}
