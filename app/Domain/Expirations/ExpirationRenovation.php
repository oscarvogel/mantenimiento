<?php

declare(strict_types=1);

namespace App\Domain\Expirations;

use DateTimeImmutable;
use DomainException;

/**
 * Evento inmutable de renovacion de un vencimiento.
 *
 * No es la fila "vigente" del vencimiento: la vigencia sigue viviendo en
 * App\Domain\Expirations\Expiration. Este value object representa UN cambio
 * historico (anterior -> nuevo) y nunca debe sobrescribirse.
 */
final class ExpirationRenovation
{
    public readonly ?string $notes;

    public function __construct(
        public readonly int $companyId,
        public readonly int $expirationId,
        public readonly DateTimeImmutable $previousDate,
        public readonly DateTimeImmutable $newDate,
        public readonly DateTimeImmutable $renovatedAt,
        public readonly ?int $userId,
        ?string $notes,
        public readonly ?int $evidenceId = null,
        public readonly ?int $id = null,
    ) {
        if ($companyId <= 0) {
            throw new DomainException('La empresa de la renovacion debe ser valida.');
        }
        if ($expirationId <= 0) {
            throw new DomainException('El vencimiento asociado a la renovacion debe ser valido.');
        }
        if ($newDate < $previousDate) {
            throw new DomainException('La fecha nueva no puede ser anterior a la fecha previa del vencimiento.');
        }
        if ($newDate->format('Y-m-d') === $previousDate->format('Y-m-d')) {
            throw new DomainException('La fecha nueva debe ser distinta de la fecha previa para registrar la renovacion.');
        }
        if ($userId !== null && $userId <= 0) {
            throw new DomainException('El usuario que registro la renovacion debe ser valido.');
        }
        if ($evidenceId !== null && $evidenceId <= 0) {
            throw new DomainException('La evidencia asociada a la renovacion debe ser valida.');
        }
        $normalized = $notes === null ? null : trim($notes);
        if ($normalized === '' || $normalized === null) {
            $this->notes = null;
        } elseif (strlen($normalized) > 2000) {
            throw new DomainException('Las observaciones de la renovacion admiten hasta 2000 caracteres.');
        } else {
            $this->notes = $normalized;
        }
    }
}
