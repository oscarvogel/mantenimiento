<?php

declare(strict_types=1);

namespace App\Application\Audit;

use DateTimeImmutable;
use InvalidArgumentException;

final class AuditEntry
{
    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    public function __construct(
        public readonly AuditScope $scope,
        public readonly ?int $companyId,
        public readonly ?int $branchId,
        public readonly AuditAction $action,
        public readonly string $entity,
        public readonly ?string $entityId,
        public readonly ?int $actorUserId,
        public readonly ?int $actorCompanyId,
        public readonly ?array $before = null,
        public readonly ?array $after = null,
        public readonly ?string $reason = null,
        public readonly ?string $ip = null,
        public readonly ?string $userAgent = null,
        public readonly ?string $requestId = null,
        public readonly ?DateTimeImmutable $occurredAt = null,
    ) {
        if (trim($this->entity) === '') {
            throw new InvalidArgumentException('La entidad auditada es obligatoria.');
        }

        if ($this->entityId !== null && trim($this->entityId) === '') {
            throw new InvalidArgumentException('El identificador de la entidad no puede estar vacío.');
        }

        if ($this->before === null && $this->after === null) {
            throw new InvalidArgumentException('La auditoría requiere valores anteriores o nuevos.');
        }

        if ($this->actorUserId !== null && $this->actorUserId <= 0) {
            throw new InvalidArgumentException('El usuario actor debe ser válido.');
        }

        if ($this->actorCompanyId !== null && $this->actorCompanyId <= 0) {
            throw new InvalidArgumentException('La empresa del actor debe ser válida.');
        }

        match ($this->scope) {
            AuditScope::GLOBAL => $this->assertGlobal(),
            AuditScope::EMPRESA => $this->assertCompany(),
            AuditScope::SUCURSAL => $this->assertBranch(),
        };
    }

    private function assertGlobal(): void
    {
        if ($this->companyId !== null || $this->branchId !== null) {
            throw new InvalidArgumentException('El alcance global no admite empresa ni sucursal afectada.');
        }
    }

    private function assertCompany(): void
    {
        if ($this->companyId === null || $this->companyId <= 0) {
            throw new InvalidArgumentException('El alcance de empresa requiere empresa afectada.');
        }

        if ($this->branchId !== null) {
            throw new InvalidArgumentException('El alcance de empresa no admite sucursal afectada.');
        }
    }

    private function assertBranch(): void
    {
        if ($this->companyId === null || $this->companyId <= 0) {
            throw new InvalidArgumentException('El alcance de sucursal requiere empresa afectada.');
        }

        if ($this->branchId === null || $this->branchId <= 0) {
            throw new InvalidArgumentException('El alcance de sucursal requiere sucursal afectada.');
        }
    }
}
