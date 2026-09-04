<?php

declare(strict_types=1);

namespace App\Infrastructure\PublicEquipmentAccess;

use App\Application\PublicEquipmentAccess\Port\PublicEquipmentTokenRepository;
use CodeIgniter\Database\BaseConnection;
use Throwable;

final class CodeIgniterPublicEquipmentTokenRepository implements PublicEquipmentTokenRepository
{
    public function __construct(private readonly BaseConnection $database)
    {
    }

    public function activeTokenHashForEquipment(int $companyId, int $equipmentId): ?string
    {
        $row = $this->database->table('equipo_tokens_publicos')
            ->select('token_hash')
            ->where('empresa_id', $companyId)
            ->where('equipo_id', $equipmentId)
            ->where('activo', 1)
            ->get()
            ->getRowArray();

        return $row === null ? null : (string) $row['token_hash'];
    }

    public function replaceActiveToken(
        int $companyId,
        int $equipmentId,
        string $tokenHash,
        ?int $actorUserId,
        string $occurredAt,
    ): bool {
        $equipment = $this->database->table('equipos')
            ->select('id')
            ->where('id', $equipmentId)
            ->where('empresa_id', $companyId)
            ->where('estado', 'ACTIVO')
            ->where('deleted_at', null)
            ->get()
            ->getRowArray();
        if ($equipment === null) {
            return false;
        }

        $this->database->transBegin();
        try {
            $this->database->table('equipo_tokens_publicos')
                ->where('empresa_id', $companyId)
                ->where('equipo_id', $equipmentId)
                ->where('activo', 1)
                ->update([
                    'activo' => 0,
                    'revoked_by' => $actorUserId,
                    'revoked_at' => $occurredAt,
                ]);

            $this->database->table('equipo_tokens_publicos')->insert([
                'empresa_id' => $companyId,
                'equipo_id' => $equipmentId,
                'token_hash' => $tokenHash,
                'activo' => 1,
                'created_by' => $actorUserId,
                'created_at' => $occurredAt,
            ]);

            if (! $this->database->transStatus()) {
                $this->database->transRollback();
                return false;
            }
            $this->database->transCommit();
            return true;
        } catch (Throwable $exception) {
            $this->database->transRollback();
            throw $exception;
        }
    }

    public function resolveActiveToken(string $tokenHash): ?array
    {
        $row = $this->database->table('equipo_tokens_publicos t')
            ->select('t.id, t.empresa_id, t.equipo_id, e.codigo, e.patente, e.estado')
            ->join('equipos e', 'e.id = t.equipo_id AND e.empresa_id = t.empresa_id', 'inner')
            ->where('t.token_hash', $tokenHash)
            ->where('t.activo', 1)
            ->where('t.revoked_at', null)
            ->where('e.deleted_at', null)
            ->get()
            ->getRowArray();

        if ($row === null) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'empresa_id' => (int) $row['empresa_id'],
            'equipo_id' => (int) $row['equipo_id'],
            'codigo' => (string) $row['codigo'],
            'patente' => $row['patente'] === null ? null : (string) $row['patente'],
            'estado' => (string) $row['estado'],
        ];
    }
}
