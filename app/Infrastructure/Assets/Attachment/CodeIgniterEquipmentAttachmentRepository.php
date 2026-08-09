<?php

declare(strict_types=1);

namespace App\Infrastructure\Assets\Attachment;

use App\Application\Assets\Attachment\Port\EquipmentAttachmentRepository;
use App\Domain\Assets\EquipmentAttachment;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;
use RuntimeException;

final class CodeIgniterEquipmentAttachmentRepository implements EquipmentAttachmentRepository
{
    public function __construct(private readonly BaseConnection $database)
    {
    }

    public function add(EquipmentAttachment $attachment): int
    {
        $createdAt = $attachment->createdAt()->format('Y-m-d H:i:s');
        $ok = $this->database->table('equipo_adjuntos')->insert([
            'empresa_id'           => $attachment->companyId(),
            'equipo_id'            => $attachment->equipmentId(),
            'sucursal_snapshot_id' => $attachment->branchSnapshotId(),
            'tipo'                 => $attachment->type(),
            'nombre_original'      => $attachment->originalName(),
            'nombre_almacenado'    => $attachment->storedName(),
            'ruta_privada'         => $attachment->privateRelativePath(),
            'mime_type'            => $attachment->mimeType(),
            'tamanio'              => $attachment->size(),
            'descripcion'          => $attachment->description(),
            'created_by'           => $attachment->createdBy(),
            'created_at'           => $createdAt,
            'updated_at'           => $createdAt,
        ]);
        $attachmentId = (int) $this->database->insertID();
        if (! $ok || $attachmentId <= 0) {
            throw new RuntimeException('No se pudieron guardar los metadatos del adjunto.');
        }

        return $attachmentId;
    }

    public function findActiveScoped(
        int $companyId,
        int $equipmentId,
        int $attachmentId,
        ?array $authorizedBranchIds,
    ): ?EquipmentAttachment {
        if ($authorizedBranchIds === []) {
            return null;
        }

        $row = $this->scopedBuilder($companyId, $equipmentId, $authorizedBranchIds)
            ->select([
                'a.id', 'a.empresa_id', 'a.equipo_id', 'a.sucursal_snapshot_id', 'a.tipo',
                'a.nombre_original', 'a.nombre_almacenado', 'a.ruta_privada', 'a.mime_type',
                'a.tamanio', 'a.descripcion', 'a.created_by', 'a.created_at', 'a.retirado_at',
                'a.retirado_by', 'a.motivo_retiro',
            ])
            ->where('a.id', $attachmentId)
            ->where('a.retirado_at', null)
            ->get()
            ->getRowArray();
        if ($row === null) {
            return null;
        }

        return EquipmentAttachment::reconstitute(
            (int) $row['id'],
            (int) $row['empresa_id'],
            (int) $row['equipo_id'],
            (int) $row['sucursal_snapshot_id'],
            (string) $row['tipo'],
            (string) $row['nombre_original'],
            (string) $row['nombre_almacenado'],
            (string) $row['ruta_privada'],
            (string) $row['mime_type'],
            (int) $row['tamanio'],
            $row['descripcion'] === null ? null : (string) $row['descripcion'],
            (int) $row['created_by'],
            new DateTimeImmutable((string) $row['created_at']),
            $row['retirado_at'] === null ? null : new DateTimeImmutable((string) $row['retirado_at']),
            $row['retirado_by'] === null ? null : (int) $row['retirado_by'],
            $row['motivo_retiro'] === null ? null : (string) $row['motivo_retiro'],
        );
    }

    public function saveRetirement(EquipmentAttachment $attachment): void
    {
        if ($attachment->id() === null || $attachment->retiredAt() === null || $attachment->retiredBy() === null) {
            throw new RuntimeException('El retiro del adjunto no está completo.');
        }

        $this->database->table('equipo_adjuntos')
            ->where('id', $attachment->id())
            ->where('empresa_id', $attachment->companyId())
            ->where('equipo_id', $attachment->equipmentId())
            ->where('retirado_at', null)
            ->update([
                'retirado_at'  => $attachment->retiredAt()->format('Y-m-d H:i:s'),
                'retirado_by'  => $attachment->retiredBy(),
                'motivo_retiro'=> $attachment->retirementReason(),
                'updated_at'   => $attachment->retiredAt()->format('Y-m-d H:i:s'),
            ]);
        if ($this->database->affectedRows() !== 1) {
            throw new RuntimeException('No se pudo retirar el adjunto de forma consistente.');
        }
    }

    /** @param list<int>|null $authorizedBranchIds */
    private function scopedBuilder(int $companyId, int $equipmentId, ?array $authorizedBranchIds): BaseBuilder
    {
        $builder = $this->database->table('equipo_adjuntos a')
            ->join('equipos e', 'e.id = a.equipo_id AND e.empresa_id = a.empresa_id', 'inner')
            ->where('a.empresa_id', $companyId)
            ->where('a.equipo_id', $equipmentId)
            ->where('e.deleted_at', null);
        if ($authorizedBranchIds !== null) {
            $builder->whereIn('e.sucursal_id', $authorizedBranchIds);
        }

        return $builder;
    }
}
