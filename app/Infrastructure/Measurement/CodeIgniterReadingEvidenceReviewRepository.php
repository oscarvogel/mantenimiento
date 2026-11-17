<?php

declare(strict_types=1);

namespace App\Infrastructure\Measurement;

use App\Application\Measurement\Port\ReadingEvidenceReviewRepository;
use App\Application\Measurement\ReadingEvidenceReviewTarget;
use App\Domain\Measurement\ReadingEvidenceReviewReason;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use DateTimeImmutable;
use DomainException;

final readonly class CodeIgniterReadingEvidenceReviewRepository implements ReadingEvidenceReviewRepository
{
    public function __construct(private BaseConnection $database)
    {
    }

    public function findScoped(int $companyId, int $readingId, ?array $branchIds): ?ReadingEvidenceReviewTarget
    {
        $builder = $this->database->table('lecturas_equipo_evidencias ev')
            ->select('ev.id evidence_id, ev.lectura_id, ev.metodo_carga, ev.km_detectado_ia, ev.km_confirmado, '
                . 'l.equipo_id, l.sucursal_id, e.codigo equipment_code, '
                . 'r.revisado_at, r.motivo, r.observacion, u.nombre reviewed_by_name', false)
            ->join('lecturas_equipo l', 'l.id = ev.lectura_id AND l.empresa_id = ev.empresa_id', 'inner')
            ->join('equipos e', 'e.id = l.equipo_id AND e.empresa_id = l.empresa_id', 'inner')
            ->join('lecturas_equipo_evidencias_revisiones r', 'r.evidencia_id = ev.id AND r.empresa_id = ev.empresa_id', 'left')
            ->join('usuarios u', 'u.id = r.revisado_por', 'left')
            ->where('ev.empresa_id', $companyId)
            ->where('ev.lectura_id', $readingId);

        if ($branchIds !== null) {
            if ($branchIds === []) {
                $builder->where('1 = 0', null, false);
            } else {
                $builder->whereIn('l.sucursal_id', $branchIds);
            }
        }

        $row = $builder->get()->getRowArray();
        if ($row === null) {
            return null;
        }

        return new ReadingEvidenceReviewTarget(
            (int) $row['evidence_id'],
            (int) $row['lectura_id'],
            (int) $row['equipo_id'],
            (int) $row['sucursal_id'],
            (string) $row['equipment_code'],
            $row['metodo_carga'] === null ? null : (string) $row['metodo_carga'],
            $row['km_detectado_ia'] === null ? null : (int) $row['km_detectado_ia'],
            $row['km_confirmado'] === null ? null : (int) $row['km_confirmado'],
            $row['revisado_at'] === null ? null : new DateTimeImmutable((string) $row['revisado_at']),
            $row['reviewed_by_name'] === null ? null : (string) $row['reviewed_by_name'],
            $row['motivo'] === null ? null : (string) $row['motivo'],
            $row['observacion'] === null ? null : (string) $row['observacion'],
        );
    }

    public function save(
        int $companyId,
        ReadingEvidenceReviewTarget $target,
        ReadingEvidenceReviewReason $reason,
        ?string $observation,
        int $actorUserId,
        DateTimeImmutable $at,
    ): void {
        try {
            $inserted = $this->database->table('lecturas_equipo_evidencias_revisiones')->insert([
                'empresa_id' => $companyId,
                'evidencia_id' => $target->evidenceId,
                'lectura_id' => $target->readingId,
                'motivo' => $reason->value,
                'observacion' => $observation,
                'revisado_por' => $actorUserId,
                'revisado_at' => $at->format('Y-m-d H:i:s'),
                'created_at' => $at->format('Y-m-d H:i:s'),
            ]);
        } catch (DatabaseException) {
            throw new DomainException('La evidencia ya fue revisada.');
        }

        if ($inserted !== true) {
            throw new DomainException('No se pudo registrar la revisión de la evidencia.');
        }
    }
}
