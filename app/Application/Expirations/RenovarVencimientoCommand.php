<?php

declare(strict_types=1);

namespace App\Application\Expirations;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Comando de entrada para el caso de uso RenovarVencimiento.
 *
 * Se construye a partir del controller (POST con CSRF) y se valida en el
 * constructor: una sola fecha nueva, evidencia opcional con mime real
 * inspeccionado por el FileInspector. Nunca aceptar la fecha vieja desde
 * el cliente sin pasarla por Expiration::statusAt() en el adaptador.
 */
final class RenovarVencimientoCommand
{
    public function __construct(
        public readonly int $expirationId,
        public readonly DateTimeImmutable $newExpirationDate,
        public readonly ?DateTimeImmutable $newIssueDate,
        public readonly ?string $documentNumber,
        public readonly ?string $notes,
        public readonly ?string $evidenceTemporaryPath,
        public readonly ?string $evidenceOriginalName,
        public readonly ?string $evidenceMimeType,
        public readonly ?int $evidenceSize,
        public readonly string $requiredPermission,
        public readonly bool $hasEvidenceUpload,
    ) {
        if ($expirationId <= 0) {
            throw new InvalidArgumentException('El identificador del vencimiento no es valido.');
        }
        if ($notes !== null && strlen($notes) > 2000) {
            throw new InvalidArgumentException('Las observaciones de la renovacion admiten hasta 2000 caracteres.');
        }
        if ($documentNumber !== null && strlen($documentNumber) > 100) {
            throw new InvalidArgumentException('El numero de documento admite hasta 100 caracteres.');
        }
        if ($hasEvidenceUpload) {
            if ($evidenceTemporaryPath === null || $evidenceOriginalName === null
                || $evidenceMimeType === null || $evidenceSize === null) {
                throw new InvalidArgumentException('La evidencia adjunta esta incompleta.');
            }
            if ($evidenceSize <= 0) {
                throw new InvalidArgumentException('La evidencia adjunta esta vacia.');
            }
        }
        if ($requiredPermission === '') {
            throw new InvalidArgumentException('El permiso requerido para renovar no fue informado.');
        }
    }
}
