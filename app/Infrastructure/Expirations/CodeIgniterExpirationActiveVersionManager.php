<?php

declare(strict_types=1);

namespace App\Infrastructure\Expirations;

use App\Domain\Expirations\ExpirationSubjectType;
use CodeIgniter\Database\BaseConnection;

final class CodeIgniterExpirationActiveVersionManager
{
    public function __construct(private readonly BaseConnection $database)
    {
    }

    public function reconcile(
        int $companyId,
        int $typeId,
        ExpirationSubjectType $subjectType,
        int $subjectId,
        ?int $actorUserId = null,
    ): void {
        $subjectField = $subjectType === ExpirationSubjectType::EQUIPMENT ? 'equipo_id' : 'empleado_id';

        $rows = $this->database->table('vencimientos')
            ->select('id, fecha_vencimiento')
            ->where('empresa_id', $companyId)
            ->where('tipo_vencimiento_id', $typeId)
            ->where($subjectField, $subjectId)
            ->where('activo', 1)
            ->where('deleted_at', null)
            ->orderBy('fecha_vencimiento', 'DESC')
            ->orderBy('id', 'DESC')
            ->get()->getResultArray();

        if (count($rows) <= 1) {
            return;
        }

        $keepId = (int) $rows[0]['id'];
        $retireIds = [];
        foreach (array_slice($rows, 1) as $row) {
            $retireIds[] = (int) $row['id'];
        }

        if ($retireIds === []) {
            return;
        }

        $payload = [
            'activo' => 0,
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        if ($actorUserId !== null && $actorUserId > 0) {
            $payload['updated_by'] = $actorUserId;
        }

        $this->database->table('vencimientos')
            ->where('empresa_id', $companyId)
            ->whereIn('id', $retireIds)
            ->where('id !=', $keepId)
            ->update($payload);
    }
}
