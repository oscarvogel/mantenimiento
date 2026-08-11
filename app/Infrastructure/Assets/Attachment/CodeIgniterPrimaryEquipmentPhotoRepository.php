<?php

declare(strict_types=1);

namespace App\Infrastructure\Assets\Attachment;

use App\Application\Assets\Attachment\Port\PrimaryEquipmentPhotoReadModel;
use App\Application\Assets\Attachment\Port\PrimaryEquipmentPhotoRepository;
use App\Application\Assets\Attachment\PrimaryEquipmentPhoto;
use App\Application\Assets\Attachment\StoredAttachmentFile;
use App\Domain\Assets\EquipmentAttachment;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;
use RuntimeException;
use Throwable;

final class CodeIgniterPrimaryEquipmentPhotoRepository implements PrimaryEquipmentPhotoRepository, PrimaryEquipmentPhotoReadModel
{
    public function __construct(private readonly BaseConnection $database)
    {
    }

    public function replace(
        EquipmentAttachment $photo,
        ?StoredAttachmentFile $thumbnail,
        ?string $thumbnailMimeType,
        ?int $thumbnailSize,
    ): int {
        $this->database->transBegin();
        try {
            $now = $photo->createdAt()->format('Y-m-d H:i:s');
            $this->database->table('equipo_adjuntos')
                ->where('empresa_id', $photo->companyId())
                ->where('foto_principal_equipo_id', $photo->equipmentId())
                ->where('retirado_at', null)
                ->update([
                    'foto_principal_equipo_id' => null,
                    'retirado_at' => $now,
                    'retirado_by' => $photo->createdBy(),
                    'motivo_retiro' => 'Reemplazada por una nueva foto principal.',
                    'updated_at' => $now,
                ]);
            $ok = $this->database->table('equipo_adjuntos')->insert([
                'empresa_id' => $photo->companyId(),
                'equipo_id' => $photo->equipmentId(),
                'foto_principal_equipo_id' => $photo->equipmentId(),
                'sucursal_snapshot_id' => $photo->branchSnapshotId(),
                'tipo' => $photo->type(),
                'nombre_original' => $photo->originalName(),
                'nombre_almacenado' => $photo->storedName(),
                'ruta_privada' => $photo->privateRelativePath(),
                'miniatura_ruta_privada' => $thumbnail?->privateRelativePath,
                'miniatura_mime_type' => $thumbnailMimeType,
                'miniatura_tamanio' => $thumbnailSize,
                'mime_type' => $photo->mimeType(),
                'tamanio' => $photo->size(),
                'descripcion' => $photo->description(),
                'created_by' => $photo->createdBy(),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $id = (int) $this->database->insertID();
            if (! $ok || $id <= 0) {
                throw new RuntimeException('No se pudo registrar la foto principal.');
            }
            if ($this->database->transStatus() === false) {
                throw new RuntimeException('Falló la transacción de reemplazo de foto principal.');
            }
            $this->database->transCommit();

            return $id;
        } catch (Throwable $exception) {
            $this->database->transRollback();
            throw $exception;
        }
    }

    public function findScoped(int $companyId, int $equipmentId, ?array $authorizedBranchIds): ?PrimaryEquipmentPhoto
    {
        if ($authorizedBranchIds === []) {
            return null;
        }
        $row = $this->scoped($companyId, $authorizedBranchIds)
            ->select('a.id, a.equipo_id, a.nombre_original, a.ruta_privada, a.mime_type, a.tamanio, a.miniatura_ruta_privada, a.miniatura_mime_type, a.miniatura_tamanio')
            ->where('a.equipo_id', $equipmentId)
            ->where('a.foto_principal_equipo_id', $equipmentId)
            ->where('a.retirado_at', null)
            ->get()->getRowArray();
        if ($row === null) {
            return null;
        }

        return new PrimaryEquipmentPhoto(
            (int) $row['id'],
            (int) $row['equipo_id'],
            (string) $row['nombre_original'],
            (string) $row['ruta_privada'],
            (string) $row['mime_type'],
            (int) $row['tamanio'],
            $row['miniatura_ruta_privada'] === null ? null : (string) $row['miniatura_ruta_privada'],
            $row['miniatura_mime_type'] === null ? null : (string) $row['miniatura_mime_type'],
            $row['miniatura_tamanio'] === null ? null : (int) $row['miniatura_tamanio'],
        );
    }

    public function retire(
        int $companyId,
        int $equipmentId,
        ?array $authorizedBranchIds,
        int $actorUserId,
        DateTimeImmutable $when,
        string $reason,
    ): bool {
        $photo = $this->findScoped($companyId, $equipmentId, $authorizedBranchIds);
        if ($photo === null) {
            return false;
        }
        $builder = $this->database->table('equipo_adjuntos')
            ->where('id', $photo->attachmentId)
            ->where('empresa_id', $companyId)
            ->where('equipo_id', $equipmentId)
            ->where('foto_principal_equipo_id', $equipmentId)
            ->where('retirado_at', null);
        $builder->update([
            'foto_principal_equipo_id' => null,
            'retirado_at' => $when->format('Y-m-d H:i:s'),
            'retirado_by' => $actorUserId,
            'motivo_retiro' => $reason,
            'updated_at' => $when->format('Y-m-d H:i:s'),
        ]);

        return $this->database->affectedRows() === 1;
    }

    public function forEquipmentIds(int $companyId, array $equipmentIds, ?array $authorizedBranchIds): array
    {
        $equipmentIds = array_values(array_unique(array_filter(array_map('intval', $equipmentIds), static fn (int $id): bool => $id > 0)));
        if ($equipmentIds === [] || $authorizedBranchIds === []) {
            return [];
        }
        $rows = $this->scoped($companyId, $authorizedBranchIds)
            ->select('a.id, a.equipo_id, a.miniatura_ruta_privada')
            ->whereIn('a.equipo_id', $equipmentIds)
            ->where('a.retirado_at', null)
            ->get()->getResultArray();
        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['equipo_id']] = [
                'attachmentId' => (int) $row['id'],
                'equipmentId' => (int) $row['equipo_id'],
                'hasThumbnail' => $row['miniatura_ruta_privada'] !== null,
            ];
        }

        return $result;
    }

    /** @param list<int>|null $branchIds */
    private function scoped(int $companyId, ?array $branchIds): BaseBuilder
    {
        $builder = $this->database->table('equipo_adjuntos a')
            ->join('equipos e', 'e.id = a.equipo_id AND e.empresa_id = a.empresa_id', 'inner')
            ->where('a.empresa_id', $companyId)
            ->where('a.foto_principal_equipo_id IS NOT NULL', null, false)
            ->where('e.deleted_at', null);
        if ($branchIds !== null) {
            $builder->whereIn('e.sucursal_id', $branchIds);
        }

        return $builder;
    }
}
