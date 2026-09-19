<?php

declare(strict_types=1);

namespace App\Application\Expirations;

use App\Application\Assets\Attachment\Port\EquipmentAttachmentFileInspector;
use App\Application\Expirations\Port\ExpirationEvidenceFileInspector;
use App\Application\Expirations\Port\ExpirationRenovationGateway;
use App\Application\Identity\ActorContext;
use App\Domain\Assets\EquipmentAttachment;
use DateTimeImmutable;
use DomainException;
use Throwable;

/**
 * Caso de uso: Renovar un vencimiento conservando el historial.
 *
 * Reglas duras:
 *  - El vencimiento debe pertenecer a la empresa del actor. El WHERE por
 *    empresa_id lo aplica el gateway; el caso de uso valida ademas que el
 *    actor no sea superadmin (la renovacion es operativa, no global).
 *  - La fecha nueva debe ser estrictamente posterior a la fecha actual y
 *    nunca igual (renovar "al mismo dia" no aporta historial).
 *  - La evidencia opcional pasa por el inspector (mime real) y por las
 *    reglas de EquipmentAttachment::assertUpload. Esto garantiza que un
 *    PDF con extension .exe es rechazado.
 *  - Atomicidad: el gateway envuelve todo en una transaccion; si la
 *    evidencia falla o la fecha no aplica, NO se actualiza el vencimiento.
 */
final class RenovarVencimiento
{
    private const MAX_EVIDENCE_SIZE = 10_485_760; // 10 MB

    public function __construct(
        private readonly ExpirationRenovationGateway $gateway,
        private readonly ExpirationEvidenceFileInspector $evidenceInspector,
    ) {
    }

    /**
     * @return array{
     *     expirationId:int,
     *     evidenceId:?int,
     *     renovationId:int,
     *     previousDate:string,
     *     newDate:string,
     *     renovatedAt:string,
     *     userId:?int,
     *     notes:?string
     * }
     */
    public function execute(ActorContext $actor, RenovarVencimientoCommand $command, DateTimeImmutable $now): array
    {
        if ($actor->isSuperAdmin() || $actor->companyId() === null) {
            throw new DomainException('La renovacion requiere un usuario de empresa.');
        }
        if (! $actor->hasPermission($command->requiredPermission)) {
            throw new DomainException('No tenes permiso para renovar vencimientos.');
        }

        // Normalizamos observaciones (trim + longitud) aca para no repetir
        // la validacion dentro del gateway.
        $notes = $command->notes;
        if ($notes !== null) {
            $notes = trim($notes);
            if ($notes === '') {
                $notes = null;
            } elseif (strlen($notes) > 2000) {
                throw new DomainException('Las observaciones de la renovacion admiten hasta 2000 caracteres.');
            }
        }

        $documentNumber = $command->documentNumber;
        if ($documentNumber !== null) {
            $documentNumber = trim($documentNumber);
            if ($documentNumber === '') {
                $documentNumber = null;
            } elseif (strlen($documentNumber) > 100) {
                throw new DomainException('El numero de documento admite hasta 100 caracteres.');
            }
        }

        // Pre-inspeccion de la evidencia: si falla, NO abrimos transaccion.
        // El inspector verifica mime real con fileinfo; assertUpload valida
        // la consistencia entre extension declarada y mime real + tamano.
        $inspectedMime = null;
        $inspectedSize = null;
        if ($command->hasEvidenceUpload) {
            $inspected = $this->evidenceInspector->inspect((string) $command->evidenceTemporaryPath);
            EquipmentAttachment::assertUpload(
                (string) $command->evidenceOriginalName,
                $inspected->mimeType,
                $inspected->size,
                self::MAX_EVIDENCE_SIZE,
            );
            $inspectedMime = $inspected->mimeType;
            $inspectedSize = $inspected->size;
        }

        $normalizedCommand = new RenovarVencimientoCommand(
            $command->expirationId,
            $command->newExpirationDate,
            $command->newIssueDate,
            $documentNumber,
            $notes,
            $command->evidenceTemporaryPath,
            $command->evidenceOriginalName,
            $inspectedMime ?? $command->evidenceMimeType,
            $inspectedSize ?? $command->evidenceSize,
            $command->requiredPermission,
            $command->hasEvidenceUpload,
        );

        try {
            $result = $this->gateway->renew(
                (int) $actor->companyId(),
                $normalizedCommand,
                $now,
                $actor->userId(),
            );
        } catch (Throwable $exception) {
            // El gateway hace rollback completo si la transaccion falla
            // despues de mover el archivo al almacenamiento. La pieza queda
            // como orphan para limpieza operacional.
            throw $exception;
        }

        if ($result === null) {
            throw new DomainException('El vencimiento no existe o no pertenece a tu empresa.');
        }

        return $result;
    }
}
