<?php

declare(strict_types=1);

namespace App\Infrastructure\Expirations;

use App\Application\Expirations\Port\ExpirationEvidenceStorage;
use App\Application\Expirations\Port\ExpirationRenovationGateway;
use App\Application\Expirations\RenovarVencimientoCommand;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;
use DomainException;
use RuntimeException;
use Throwable;

final class CodeIgniterExpirationRenovationGateway implements ExpirationRenovationGateway
{
    public function __construct(
        private readonly BaseConnection $database,
        private readonly ExpirationEvidenceStorage $evidenceStorage,
    ) {
    }

    public function renew(
        int $companyId,
        RenovarVencimientoCommand $command,
        DateTimeImmutable $now,
        int $actorUserId,
    ): ?array {
        if ($companyId <= 0) {
            throw new DomainException('La renovacion requiere una empresa valida.');
        }

        $this->database->transStart();

        try {
            $row = $this->database->table('vencimientos')
                ->select('id, empresa_id, tipo_vencimiento_id, sujeto_tipo, equipo_id, empleado_id, fecha_vencimiento')
                ->where('empresa_id', $companyId)
                ->where('id', $command->expirationId)
                ->where('deleted_at', null)
                ->get()->getRowArray();

            if ($row === null) {
                $this->database->transRollback();

                return null;
            }

            if ((int) $row['empresa_id'] !== $companyId) {
                // Defensa redundante: el WHERE ya filtra por empresa_id.
                $this->database->transRollback();
                throw new DomainException('El vencimiento no pertenece a tu empresa.');
            }

            $currentDate = new DateTimeImmutable((string) $row['fecha_vencimiento']);
            $newDate = $command->newExpirationDate;
            if ($newDate <= $currentDate) {
                $this->database->transRollback();
                throw new DomainException('La nueva fecha de vencimiento debe ser posterior a la fecha actual.');
            }

            $typeId = (int) $row['tipo_vencimiento_id'];
            $subjectType = (string) $row['sujeto_tipo'];
            $subjectId = (int) ($subjectType === 'EQUIPO' ? $row['equipo_id'] : $row['empleado_id']);

            // 1) Evidencia (si vino). La pieza se sube al almacenamiento
            //    privado ANTES del insert SQL para que el path persistido
            //    exista en disco; si el insert falla, hacemos delete abajo.
            $evidenceId = null;
            $storedRelativePath = null;
            if ($command->hasEvidenceUpload) {
                $extension = $this->canonicalExtension((string) $command->evidenceMimeType);
                $stored = $this->evidenceStorage->store(
                    (string) $command->evidenceTemporaryPath,
                    $companyId,
                    $extension,
                );
                $storedRelativePath = $stored->privateRelativePath;
                $nowSql = $now->format('Y-m-d H:i:s');
                $this->database->table('vencimiento_evidencias')->insert([
                    'empresa_id' => $companyId,
                    'vencimiento_id' => $command->expirationId,
                    'nombre_original' => (string) $command->evidenceOriginalName,
                    'nombre_almacenado' => $stored->storedName,
                    'ruta_privada' => $stored->privateRelativePath,
                    'mime_type' => (string) $command->evidenceMimeType,
                    'tamanio' => (int) $command->evidenceSize,
                    'created_by' => $actorUserId,
                    'created_at' => $nowSql,
                ]);
                $evidenceId = (int) $this->database->insertID();
            }

            // 2) Historial append-only.
            $nowSql = $now->format('Y-m-d H:i:s');
            $this->database->table('vencimiento_renovaciones')->insert([
                'empresa_id' => $companyId,
                'vencimiento_id' => $command->expirationId,
                'fecha_anterior' => $currentDate->format('Y-m-d'),
                'fecha_nueva' => $newDate->format('Y-m-d'),
                'fecha_renovacion' => $nowSql,
                'usuario_id' => $actorUserId > 0 ? $actorUserId : null,
                'observaciones' => $command->notes,
                'evidencia_id' => $evidenceId,
                'created_at' => $nowSql,
                'updated_at' => $nowSql,
            ]);
            $renovationId = (int) $this->database->insertID();

            // 3) Update del vencimiento vigente (mantenemos numero_documento
            //    y observaciones actualizables para no romper el flujo de
            //    "renovar y de paso actualizar el documento").
            $updatePayload = [
                'fecha_emision' => $command->newIssueDate?->format('Y-m-d'),
                'fecha_vencimiento' => $newDate->format('Y-m-d'),
                'numero_documento' => $command->documentNumber,
                'observaciones' => $command->notes,
                'updated_by' => $actorUserId > 0 ? $actorUserId : null,
                'updated_at' => $nowSql,
            ];
            $this->database->table('vencimientos')
                ->where('empresa_id', $companyId)
                ->where('id', $command->expirationId)
                ->update($updatePayload);

            $this->database->transComplete();
            if ($this->database->transStatus() === false) {
                throw new RuntimeException('La transaccion de renovacion fallo y se aplico rollback.');
            }

            return [
                'expirationId' => $command->expirationId,
                'evidenceId' => $evidenceId,
                'renovationId' => $renovationId,
                'previousDate' => $currentDate->format('Y-m-d'),
                'newDate' => $newDate->format('Y-m-d'),
                'renovatedAt' => $nowSql,
                'userId' => $actorUserId > 0 ? $actorUserId : null,
                'notes' => $command->notes,
                'typeId' => $typeId,
                'subjectType' => $subjectType,
                'subjectId' => $subjectId,
            ];
        } catch (Throwable $exception) {
            // Si la evidencia ya quedo en disco, la limpiamos para no dejar
            // orphan. No bloqueamos la propagacion del error original.
            if (isset($storedRelativePath)) {
                try {
                    $this->evidenceStorage->delete($storedRelativePath);
                } catch (Throwable) {
                    // Cleanup best-effort.
                }
            }
            $this->database->transRollback();

            throw $exception;
        }
    }

    private function canonicalExtension(string $mimeType): string
    {
        $map = [
            'application/pdf' => 'pdf',
            'image/jpeg'      => 'jpg',
            'image/png'       => 'png',
            'image/webp'      => 'webp',
        ];
        $mimeType = strtolower(trim($mimeType));
        if (! isset($map[$mimeType])) {
            throw new DomainException('El tipo de evidencia no esta permitido.');
        }

        return $map[$mimeType];
    }
}
