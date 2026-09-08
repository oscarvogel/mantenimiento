<?php

declare(strict_types=1);

namespace App\Domain\Expirations;

use DateTimeImmutable;
use DomainException;

final class Expiration
{
    public function __construct(
        public readonly int $companyId,
        public readonly int $typeId,
        public readonly ExpirationSubjectType $subjectType,
        public readonly int $subjectId,
        public readonly DateTimeImmutable $expiresAt,
        public readonly int $warningDays,
        public readonly ?DateTimeImmutable $issuedAt = null,
        public readonly ?string $documentNumber = null,
        public readonly ?string $notes = null,
        public readonly ?int $branchId = null,
        public readonly ?int $id = null,
    ) {
        if ($companyId <= 0 || $typeId <= 0 || $subjectId <= 0) {
            throw new DomainException('Empresa, tipo y sujeto del vencimiento deben ser válidos.');
        }
        if ($warningDays < 0) {
            throw new DomainException('Los días de aviso previo no pueden ser negativos.');
        }
        if ($issuedAt !== null && $issuedAt > $expiresAt) {
            throw new DomainException('La fecha de emisión no puede ser posterior al vencimiento.');
        }
        if ($branchId !== null && $branchId <= 0) {
            throw new DomainException('La sucursal del vencimiento debe ser válida.');
        }
    }

    public function statusAt(DateTimeImmutable $today): ExpirationStatus
    {
        $today = new DateTimeImmutable($today->format('Y-m-d'));
        $expires = new DateTimeImmutable($this->expiresAt->format('Y-m-d'));

        if ($expires < $today) {
            return ExpirationStatus::OVERDUE;
        }

        if ($expires <= $today->modify('+' . $this->warningDays . ' days')) {
            return ExpirationStatus::DUE_SOON;
        }

        return ExpirationStatus::CURRENT;
    }

    public function daysUntil(DateTimeImmutable $today): int
    {
        $today = new DateTimeImmutable($today->format('Y-m-d'));
        $expires = new DateTimeImmutable($this->expiresAt->format('Y-m-d'));
        return (int) $today->diff($expires)->format('%r%a');
    }
}
