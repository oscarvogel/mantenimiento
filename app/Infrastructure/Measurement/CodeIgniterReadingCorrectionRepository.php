<?php

declare(strict_types=1);

namespace App\Infrastructure\Measurement;

use App\Application\Measurement\Port\ReadingCorrectionRepository;
use App\Application\Measurement\Port\WorkOrderReadingCorrectionSynchronizer;
use App\Domain\Measurement\EquipmentReading;
use App\Domain\Measurement\UsageMeasurement;
use App\Infrastructure\Notifications\CodeIgniterCompanyNotificationRecipientResolver;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;
use RuntimeException;

final class CodeIgniterReadingCorrectionRepository implements ReadingCorrectionRepository, WorkOrderReadingCorrectionSynchronizer
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

    public function synchronizeFinalizedWorkOrder(
        EquipmentReading $original,
        UsageMeasurement $replacement,
        int $correctionReadingId,
        int $actorUserId,
        string $reason,
        ?string $notes,
        DateTimeImmutable $correctedAt,
    ): void {
        if ($original->origin() !== EquipmentReading::WORK_ORDER) {
            return;
        }

        $reference = trim((string) $original->originReference());
        if (preg_match('/^OT#(\d+)$/', $reference, $matches) !== 1) {
            return;
        }

        $orderId = (int) $matches[1];
        $row = $this->database->query(
            'SELECT id, numero, empresa_id, sucursal_id, equipo_id, estado, km_salida, horas_salida, trabajo_realizado '
            . 'FROM ordenes_trabajo WHERE id = ? AND empresa_id = ? AND equipo_id = ? FOR UPDATE',
            [$orderId, $original->companyId(), $original->equipmentId()],
        )->getRowArray();

        if ($row === null) {
            throw new RuntimeException('La lectura referencia una OT que no existe o no pertenece al equipo.');
        }
        if ((string) $row['estado'] !== 'FINALIZADA') {
            return;
        }

        $oldKm = $row['km_salida'] === null ? null : (int) $row['km_salida'];
        $oldHours = $row['horas_salida'] === null ? null : (string) $row['horas_salida'];
        $newKm = $replacement->kilometers();
        $newHours = $replacement->hours();
        $timestamp = $correctedAt->format('Y-m-d H:i:s');

        $this->database->table('ordenes_trabajo')
            ->where('id', $orderId)
            ->where('empresa_id', $original->companyId())
            ->where('equipo_id', $original->equipmentId())
            ->where('estado', 'FINALIZADA')
            ->update([
                'km_salida' => $newKm,
                'horas_salida' => $newHours,
                'updated_at' => $timestamp,
                'updated_by' => $actorUserId,
            ]);

        if ($this->database->affectedRows() !== 1) {
            throw new RuntimeException('La OT cerrada cambió durante la rectificación.');
        }

        $detail = 'Rectificación de lectura en OT cerrada. '
            . 'KM: ' . $this->displayValue($oldKm) . ' → ' . $this->displayValue($newKm)
            . '; Horas: ' . $this->displayValue($oldHours) . ' → ' . $this->displayValue($newHours)
            . '; motivo: ' . trim($reason)
            . '; lectura de corrección #' . $correctionReadingId;
        $notes = trim((string) $notes);
        if ($notes !== '') {
            $detail .= '; observaciones: ' . $notes;
        }

        $this->database->table('orden_estado_historial')->insert([
            'empresa_id' => $original->companyId(),
            'orden_id' => $orderId,
            'estado_anterior' => 'FINALIZADA',
            'estado_nuevo' => 'FINALIZADA',
            'fecha' => $timestamp,
            'usuario_id' => $actorUserId,
            'comentario' => mb_substr($detail, 0, 255),
            'created_at' => $timestamp,
        ]);

        $recipient = (new CodeIgniterCompanyNotificationRecipientResolver($this->database))
            ->resolve($original->companyId());
        $missingRecipient = $recipient === null;
        $actor = $this->database->table('usuarios')
            ->select('nombre')
            ->where('id', $actorUserId)
            ->where('empresa_id', $original->companyId())
            ->get()->getRowArray();
        $actorName = trim((string) ($actor['nombre'] ?? ''));
        $actorLabel = $actorName === '' ? 'usuario #' . $actorUserId : $actorName . ' (#' . $actorUserId . ')';
        $title = 'OT ' . (string) $row['numero'] . ' rectificada después de su cierre';
        $summary = 'Equipo #' . $original->equipmentId()
            . '. KM: ' . $this->displayValue($oldKm) . ' → ' . $this->displayValue($newKm)
            . '. Horas: ' . $this->displayValue($oldHours) . ' → ' . $this->displayValue($newHours)
            . '. Motivo: ' . trim($reason)
            . '. Modificado por ' . $actorLabel . '.';

        $this->database->table('notificacion_empresa_entregas')->ignore(true)->insert([
            'empresa_id' => $original->companyId(),
            'tipo_evento' => 'orden.rectificada',
            'destinatario' => $recipient,
            'clave_entrega' => 'orden.rectificada:' . $orderId . ':lectura:' . $correctionReadingId . ':empresa:' . $original->companyId() . ':email',
            'titulo' => $title,
            'resumen' => mb_substr($summary, 0, 1000),
            'url' => '/mantenimiento/equipos/' . $original->equipmentId(),
            'estado' => $missingRecipient ? 'OMITIDA' : 'PENDIENTE',
            'ultimo_error' => $missingRecipient ? 'Empresa sin destinatario de notificaciones por email habilitado.' : null,
            'created_at' => $timestamp,
        ]);
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

    private function displayValue(int|string|null $value): string
    {
        return $value === null || trim((string) $value) === '' ? '—' : (string) $value;
    }
}
