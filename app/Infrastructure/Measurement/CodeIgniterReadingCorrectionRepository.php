<?php

declare(strict_types=1);

namespace App\Infrastructure\Measurement;

use App\Application\Measurement\Port\ReadingCorrectionRepository;
use App\Domain\Measurement\EquipmentReading;
use App\Domain\Measurement\UsageMeasurement;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;
use RuntimeException;

final class CodeIgniterReadingCorrectionRepository implements ReadingCorrectionRepository
{
    public function __construct(private readonly BaseConnection $database)
    {
    }

    public function findForUpdate(int $readingId, int $companyId, int $equipmentId): ?EquipmentReading
    {
        $row = $this->database->query(
            'SELECT id, empresa_id, sucursal_id, equipo_id, fecha_lectura, kilometraje, horometro, '
            . 'origen, referencia_origen, usuario_id, motivo_correccion, lectura_corregida_id, '
            . 'observaciones, anulada, anulada_at, anulada_por, motivo_anulacion '
            . 'FROM lecturas_equipo WHERE id = ? AND empresa_id = ? AND equipo_id = ? FOR UPDATE',
            [$readingId, $companyId, $equipmentId],
        )->getRowArray();
        if ($row === null) {
            return null;
        }

        return EquipmentReading::reconstitute(
            (int) $row['id'],
            (int) $row['empresa_id'],
            (int) $row['sucursal_id'],
            (int) $row['equipo_id'],
            new DateTimeImmutable((string) $row['fecha_lectura']),
            UsageMeasurement::from(
                $row['kilometraje'] === null ? null : (int) $row['kilometraje'],
                $row['horometro'] === null ? null : (string) $row['horometro'],
            ),
            (string) $row['origen'],
            $row['referencia_origen'] === null ? null : (string) $row['referencia_origen'],
            (int) $row['usuario_id'],
            $row['motivo_correccion'] === null ? null : (string) $row['motivo_correccion'],
            $row['observaciones'] === null ? null : (string) $row['observaciones'],
            $row['lectura_corregida_id'] === null ? null : (int) $row['lectura_corregida_id'],
            (bool) $row['anulada'],
            $row['anulada_at'] === null ? null : new DateTimeImmutable((string) $row['anulada_at']),
            $row['anulada_por'] === null ? null : (int) $row['anulada_por'],
            $row['motivo_anulacion'] === null ? null : (string) $row['motivo_anulacion'],
        );
    }

    public function markAnnulled(EquipmentReading $reading): void
    {
        if ($reading->id() === null || ! $reading->isAnnulled()
            || $reading->annulledAt() === null || $reading->annulledBy() === null) {
            throw new RuntimeException('La lectura no contiene una anulación persistible.');
        }

        $this->database->table('lecturas_equipo')
            ->where('id', $reading->id())
            ->where('empresa_id', $reading->companyId())
            ->where('equipo_id', $reading->equipmentId())
            ->where('anulada', 0)
            ->update([
                'anulada'           => 1,
                'anulada_at'        => $reading->annulledAt()->format('Y-m-d H:i:s'),
                'anulada_por'       => $reading->annulledBy(),
                'motivo_anulacion'  => $reading->annulmentReason(),
                'updated_at'        => $reading->annulledAt()->format('Y-m-d H:i:s'),
            ]);

        if ($this->database->affectedRows() !== 1) {
            throw new RuntimeException('La lectura ya fue anulada o cambió durante la corrección.');
        }
    }

    public function recalculateCurrentUsage(
        int $companyId,
        int $branchId,
        int $equipmentId,
        int $actorUserId,
    ): UsageMeasurement {
        $kilometers = $this->latestValue($companyId, $equipmentId, 'kilometraje');
        $hours = $this->latestValue($companyId, $equipmentId, 'horometro');
        $measurement = UsageMeasurement::from(
            $kilometers === null ? null : (int) $kilometers,
            $hours === null ? null : (string) $hours,
        );

        $this->database->table('equipos')
            ->where('id', $equipmentId)
            ->where('empresa_id', $companyId)
            ->where('sucursal_id', $branchId)
            ->where('deleted_at', null)
            ->update([
                'km_actual'      => $measurement->kilometers(),
                'horas_actuales' => $measurement->hours(),
                'updated_at'     => date('Y-m-d H:i:s'),
                'updated_by'     => $actorUserId,
            ]);

        return $measurement;
    }

    private function latestValue(int $companyId, int $equipmentId, string $column): mixed
    {
        $row = $this->database->table('lecturas_equipo')
            ->select($column)
            ->where('empresa_id', $companyId)
            ->where('equipo_id', $equipmentId)
            ->where('anulada', 0)
            ->where($column . ' IS NOT NULL', null, false)
            ->orderBy('fecha_lectura', 'DESC')
            ->orderBy('id', 'DESC')
            ->limit(1)
            ->get()
            ->getRowArray();

        return $row[$column] ?? null;
    }
}
