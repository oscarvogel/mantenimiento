<?php

declare(strict_types=1);

namespace App\Domain\Expirations;

use DateTimeImmutable;
use DomainException;

final class ExpirationRegularization
{
    public function __construct(
        public readonly int $companyId,
        public readonly int $expirationId,
        public readonly ?int $employeeId,
        public readonly DateTimeImmutable $regularizedAt,
        public readonly DateTimeImmutable $proposedExpiresAt,
        public readonly ExpirationRegularizationStatus $status = ExpirationRegularizationStatus::PENDING,
        public readonly ?string $notes = null,
        public readonly ?int $id = null,
    ) {
        if ($companyId <= 0 || $expirationId <= 0) {
            throw new DomainException('Empresa y vencimiento deben ser válidos.');
        }
        if ($employeeId !== null && $employeeId <= 0) {
            throw new DomainException('El empleado debe ser válido.');
        }
    }
}
