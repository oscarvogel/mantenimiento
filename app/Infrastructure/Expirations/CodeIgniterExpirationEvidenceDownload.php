<?php

declare(strict_types=1);

namespace App\Infrastructure\Expirations;

use App\Application\Assets\Attachment\DownloadedEquipmentAttachment;
use App\Application\Expirations\Port\ExpirationEvidenceDownload;
use App\Application\Expirations\Port\ExpirationEvidenceStorage;
use CodeIgniter\Database\BaseConnection;

final class CodeIgniterExpirationEvidenceDownload implements ExpirationEvidenceDownload
{
    public function __construct(
        private readonly BaseConnection $database,
        private readonly ExpirationEvidenceStorage $storage,
    ) {
    }

    public function download(int $companyId, int $evidenceId): ?DownloadedEquipmentAttachment
    {
        if ($companyId <= 0 || $evidenceId <= 0) {
            return null;
        }

        $row = $this->database->table('vencimiento_evidencias')
            ->select('id, empresa_id, nombre_original, ruta_privada, mime_type, tamanio, deleted_at')
            ->where('empresa_id', $companyId)
            ->where('id', $evidenceId)
            ->get()->getRowArray();

        if ($row === null) {
            return null;
        }
        if ($row['deleted_at'] !== null) {
            return null;
        }
        if ((int) $row['empresa_id'] !== $companyId) {
            // Defensa redundante: el WHERE ya filtra por empresa_id.
            return null;
        }

        $content = $this->storage->read((string) $row['ruta_privada']);

        return new DownloadedEquipmentAttachment(
            (string) $row['nombre_original'],
            (string) $row['mime_type'],
            (int) $row['tamanio'],
            $content,
        );
    }
}
