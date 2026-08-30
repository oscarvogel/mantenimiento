<?php

declare(strict_types=1);

namespace App\Infrastructure\WorkOrders;

use App\Application\Identity\ActorContext;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;

/**
 * Read model for every file that belongs to a work order.
 *
 * Equipment attachments and AI-imported documents have different storage
 * records, but the work order is the common business relation exposed to the
 * user. The order scope is applied to both queries so callers cannot widen
 * access by supplying an arbitrary order or equipment id.
 */
final readonly class CodeIgniterEquipmentWorkOrderEvidenceReadModel
{
    public function __construct(private BaseConnection $database)
    {
    }

    /**
     * @param list<int> $orderIds
     * @return array<int,list<array<string,mixed>>>
     */
    public function forOrders(ActorContext $actor, int $equipmentId, array $orderIds): array
    {
        if ($actor->companyId() === null || $equipmentId <= 0 || $orderIds === []) {
            return [];
        }

        $attachments = $this->equipmentAttachments($actor, $equipmentId, $orderIds);
        $imports = $this->importedDocuments($actor, $equipmentId, $orderIds);
        $grouped = [];

        foreach (array_merge($attachments, $imports) as $row) {
            $orderId = (int) ($row['orden_id'] ?? 0);
            if ($orderId > 0) {
                $grouped[$orderId][] = $row;
            }
        }

        foreach ($grouped as &$rows) {
            usort($rows, static function (array $left, array $right): int {
                $dateComparison = strcmp((string) ($right['created_at'] ?? ''), (string) ($left['created_at'] ?? ''));
                if ($dateComparison !== 0) {
                    return $dateComparison;
                }

                return ((int) ($right['id'] ?? 0)) <=> ((int) ($left['id'] ?? 0));
            });
        }
        unset($rows);

        return $grouped;
    }

    /** @return array<string,mixed>|null */
    public function findImportedDocumentForOrder(
        ActorContext $actor,
        int $equipmentId,
        int $orderId,
        int $importId,
    ): ?array {
        if ($actor->companyId() === null || $equipmentId <= 0 || $orderId <= 0 || $importId <= 0) {
            return null;
        }

        $rows = $this->importedDocuments($actor, $equipmentId, [$orderId], $importId);

        return $rows[0] ?? null;
    }

    /** @param list<int> $orderIds @return list<array<string,mixed>> */
    private function equipmentAttachments(ActorContext $actor, int $equipmentId, array $orderIds): array
    {
        $rows = $this->scopedOrders($actor, $equipmentId)
            ->select("a.id, a.empresa_id, a.equipo_id, a.orden_id, a.nombre_original, a.mime_type, a.tamanio, a.descripcion, a.created_at, 'equipment_attachment' AS source", false)
            ->join('equipo_adjuntos a', 'a.empresa_id = o.empresa_id AND a.equipo_id = o.equipo_id AND a.orden_id = o.id', 'inner')
            ->whereIn('o.id', $orderIds)
            ->where('a.retirado_at', null)
            ->orderBy('a.created_at', 'DESC')
            ->orderBy('a.id', 'DESC')
            ->get()
            ->getResultArray();

        return $rows;
    }

    /**
     * @param list<int> $orderIds
     * @return list<array<string,mixed>>
     */
    private function importedDocuments(
        ActorContext $actor,
        int $equipmentId,
        array $orderIds,
        ?int $importId = null,
    ): array {
        $builder = $this->scopedOrders($actor, $equipmentId)
            ->select("i.id, i.empresa_id, l.orden_id, i.original_name AS nombre_original, i.mime_type, i.size_bytes AS tamanio, i.created_at, i.private_relative_path, 'ot_document_import' AS source", false)
            ->join('ot_document_import_orders l', 'l.empresa_id = o.empresa_id AND l.orden_id = o.id', 'inner')
            ->join('ot_document_imports i', 'i.empresa_id = l.empresa_id AND i.id = l.import_id', 'inner')
            ->whereIn('o.id', $orderIds)
            ->orderBy('i.created_at', 'DESC')
            ->orderBy('i.id', 'DESC');
        if ($importId !== null) {
            $builder->where('i.id', $importId);
        }

        return $builder->get()->getResultArray();
    }

    private function scopedOrders(ActorContext $actor, int $equipmentId): BaseBuilder
    {
        $builder = $this->database->table('ordenes_trabajo o')
            ->where('o.empresa_id', (int) $actor->companyId())
            ->where('o.equipo_id', $equipmentId);

        if (! $actor->hasAllCompanyBranches()) {
            $branchIds = $actor->branchIds();
            if ($branchIds === []) {
                return $builder->where('1 = 0', null, false);
            }
            $builder->whereIn('o.sucursal_id', $branchIds);
        }

        if (! $actor->hasPermission('ordenes.ver') && $actor->hasPermission('ordenes.mi_trabajo')) {
            $builder->where('o.responsable_usuario_id', $actor->userId());
        }

        return $builder;
    }
}
