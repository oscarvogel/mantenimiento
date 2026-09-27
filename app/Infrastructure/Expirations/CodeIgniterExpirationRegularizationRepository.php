<?php

declare(strict_types=1);

namespace App\Infrastructure\Expirations;

use App\Application\Expirations\Port\ExpirationRegularizationRepository;
use App\Domain\Expirations\ExpirationRegularization;
use App\Domain\Expirations\ExpirationRegularizationStatus;
use CodeIgniter\Database\BaseConnection;
use DomainException;

final class CodeIgniterExpirationRegularizationRepository implements ExpirationRegularizationRepository
{
    public function __construct(private readonly BaseConnection $database)
    {
    }

    public function hasPending(int $companyId, int $expirationId): bool
    {
        return $this->database->table('vencimiento_regularizaciones')
            ->where('empresa_id', $companyId)
            ->where('vencimiento_id', $expirationId)
            ->where('estado', ExpirationRegularizationStatus::PENDING->value)
            ->countAllResults() > 0;
    }

    public function createPending(ExpirationRegularization $regularization): int
    {
        $expiration = $this->database->table('vencimientos')
            ->select('id')
            ->where('id', $regularization->expirationId)
            ->where('empresa_id', $regularization->companyId)
            ->where('activo', 1)
            ->where('deleted_at', null)
            ->get()->getRowArray();
        if ($expiration === null) {
            throw new DomainException('El vencimiento no pertenece a la empresa o no está activo.');
        }

        if ($regularization->employeeId !== null) {
            $employee = $this->database->table('empleados')
                ->select('id')
                ->where('id', $regularization->employeeId)
                ->where('empresa_id', $regularization->companyId)
                ->where('deleted_at', null)
                ->get()->getRowArray();
            if ($employee === null) {
                throw new DomainException('El empleado no pertenece a la empresa.');
            }
        }

        $this->database->transBegin();
        try {
            // Serializa por vencimiento para que dos requests concurrentes no creen dos PENDIENTES.
            $this->database->query(
                'SELECT id FROM vencimientos WHERE id = ? AND empresa_id = ? FOR UPDATE',
                [$regularization->expirationId, $regularization->companyId]
            );
            if ($this->hasPending($regularization->companyId, $regularization->expirationId)) {
                throw new DomainException('El vencimiento ya tiene una regularización pendiente.');
            }

            $now = date('Y-m-d H:i:s');
            $this->database->table('vencimiento_regularizaciones')->insert([
                'empresa_id' => $regularization->companyId,
                'vencimiento_id' => $regularization->expirationId,
                'empleado_id' => $regularization->employeeId,
                'fecha_regularizacion' => $regularization->regularizedAt->format('Y-m-d'),
                'nueva_fecha_vencimiento' => $regularization->proposedExpiresAt->format('Y-m-d'),
                'observaciones' => $regularization->notes,
                'estado' => ExpirationRegularizationStatus::PENDING->value,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $id = (int) $this->database->insertID();
            if (! $this->database->transStatus()) {
                throw new DomainException('No se pudo registrar la regularización.');
            }
            $this->database->transCommit();
            return $id;
        } catch (\Throwable $exception) {
            $this->database->transRollback();
            throw $exception;
        }
    }

    public function reject(int $companyId, int $regularizationId, int $reviewerUserId, string $reason, string $reviewedAt): void
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException('El motivo de rechazo es obligatorio.');
        }
        $this->review($companyId, $regularizationId, $reviewerUserId, ExpirationRegularizationStatus::REJECTED, $reviewedAt, $reason);
    }

    public function approve(int $companyId, int $regularizationId, int $reviewerUserId, string $reviewedAt): void
    {
        $this->review($companyId, $regularizationId, $reviewerUserId, ExpirationRegularizationStatus::APPROVED, $reviewedAt, null);
    }

    public function addAttachment(int $companyId, int $regularizationId, string $storedPath, string $originalName, string $mimeType, int $sizeBytes): int
    {
        $parent = $this->database->table('vencimiento_regularizaciones')
            ->select('id')->where('id', $regularizationId)->where('empresa_id', $companyId)->get()->getRowArray();
        if ($parent === null) {
            throw new DomainException('La regularización no pertenece a la empresa.');
        }
        if (trim($storedPath) === '' || trim($originalName) === '' || trim($mimeType) === '' || $sizeBytes <= 0) {
            throw new DomainException('Los datos del adjunto son inválidos.');
        }
        $this->database->table('vencimiento_regularizacion_adjuntos')->insert([
            'empresa_id' => $companyId,
            'regularizacion_id' => $regularizationId,
            'ruta_archivo' => $storedPath,
            'nombre_original' => $originalName,
            'mime_type' => $mimeType,
            'tamano_bytes' => $sizeBytes,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        return (int) $this->database->insertID();
    }

    private function review(int $companyId, int $regularizationId, int $reviewerUserId, ExpirationRegularizationStatus $status, string $reviewedAt, ?string $reason): void
    {
        $row = $this->database->table('vencimiento_regularizaciones')
            ->select('id, estado')->where('id', $regularizationId)->where('empresa_id', $companyId)->get()->getRowArray();
        if ($row === null || (string) $row['estado'] !== ExpirationRegularizationStatus::PENDING->value) {
            throw new DomainException('La regularización no existe o ya fue revisada.');
        }
        $this->database->table('vencimiento_regularizaciones')
            ->where('id', $regularizationId)->where('empresa_id', $companyId)->update([
                'estado' => $status->value,
                'revisado_por' => $reviewerUserId,
                'revisado_at' => $reviewedAt,
                'motivo_rechazo' => $reason,
                'updated_at' => $reviewedAt,
            ]);
    }
}
