<?php

declare(strict_types=1);

namespace App\Infrastructure\WorkOrders\DocumentImport;

use App\Application\WorkOrders\DocumentImport\Port\WorkOrderDocumentImportRepository;
use App\Domain\WorkOrders\WorkOrderDocumentImport;
use CodeIgniter\Database\BaseConnection;
use DomainException;

final class CodeIgniterWorkOrderDocumentImportRepository implements WorkOrderDocumentImportRepository
{
    public function __construct(private readonly BaseConnection $db) {}

    public function add(WorkOrderDocumentImport $import): int
    {
        $now = $import->createdAt()->format('Y-m-d H:i:s');
        $this->db->table('ot_document_imports')->insert([
            'empresa_id' => $import->companyId(),
            'sucursal_id' => $import->branchId(),
            'created_by' => $import->createdBy(),
            'original_name' => $import->originalName(),
            'stored_name' => $import->storedName(),
            'private_relative_path' => $import->privateRelativePath(),
            'mime_type' => $import->mimeType(),
            'size_bytes' => $import->sizeBytes(),
            'sha256' => $import->sha256(),
            'idempotency_key' => $import->idempotencyKey(),
            'status' => $import->status(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $id = (int) $this->db->insertID();
        if ($id <= 0) {
            throw new DomainException('No se pudo registrar el documento de taller.');
        }
        return $id;
    }

    public function findForActor(int $importId, int $companyId, ?array $branchIds): ?array
    {
        $builder = $this->db->table('ot_document_imports')->where('id', $importId)->where('empresa_id', $companyId);
        if ($branchIds !== null) {
            if ($branchIds === []) {
                return null;
            }
            $builder->whereIn('sucursal_id', array_map('intval', $branchIds));
        }
        return $builder->get()->getRowArray() ?: null;
    }

    public function saveAnalysis(int $importId, int $companyId, array $analysis, string $status, ?string $error = null): void
    {
        $this->updateScoped($importId, $companyId, [
            'analysis_json' => $analysis === [] ? null : json_encode($analysis, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'analysis_error' => $error,
            'status' => $status,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function saveProposal(int $importId, int $companyId, array $proposal): void
    {
        $this->updateScoped($importId, $companyId, [
            'proposal_json' => json_encode($proposal, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function findByIdempotencyKey(int $companyId, string $idempotencyKey): ?int
    {
        $row = $this->db->table('ot_document_imports')->select('id')->where('empresa_id', $companyId)->where('idempotency_key', $idempotencyKey)->get()->getRowArray();
        return $row === null ? null : (int) $row['id'];
    }

    public function linkWorkOrder(int $importId, int $companyId, int $workOrderId, string $kind): void
    {
        $kind = strtoupper(trim($kind));
        if (! in_array($kind, ['CORRECTIVA', 'PREVENTIVA'], true)) {
            throw new DomainException('El tipo de vínculo de OT no es válido.');
        }
        $this->db->table('ot_document_import_orders')->ignore(true)->insert([
            'empresa_id' => $companyId,
            'import_id' => $importId,
            'orden_id' => $workOrderId,
            'kind' => $kind,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** @param array<string,mixed> $data */
    private function updateScoped(int $importId, int $companyId, array $data): void
    {
        $this->db->table('ot_document_imports')->where('id', $importId)->where('empresa_id', $companyId)->update($data);
        if ($this->db->affectedRows() < 0) {
            throw new DomainException('No se pudo actualizar la importación documental.');
        }
    }
}
