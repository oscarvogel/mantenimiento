<?php

declare(strict_types=1);

namespace App\Domain\Employees;

use DateTimeImmutable;
use DomainException;

final class EmployeeEquipmentAssignment
{
    public const ROLE_DRIVER = 'CHOFER';
    public const ROLE_OTHER = 'OTRO';

    private function __construct(
        private readonly ?int $id,
        private readonly int $companyId,
        private readonly int $employeeId,
        private readonly int $equipmentId,
        private readonly string $role,
        private readonly DateTimeImmutable $startsAt,
        private ?DateTimeImmutable $endsAt,
        private ?string $notes,
    ) {
        if ($companyId <= 0 || $employeeId <= 0 || $equipmentId <= 0) {
            throw new DomainException('La asignación debe tener empresa, empleado y móvil válidos.');
        }
        if (! in_array($role, [self::ROLE_DRIVER, self::ROLE_OTHER], true)) {
            throw new DomainException('El rol de la asignación no es válido.');
        }
        if ($endsAt !== null && $endsAt < $startsAt) {
            throw new DomainException('La fecha hasta no puede ser anterior a la fecha desde.');
        }
        $this->notes = self::normalizeNotes($notes);
    }

    public static function create(
        int $companyId,
        int $employeeId,
        int $equipmentId,
        string $role,
        DateTimeImmutable $startsAt,
        ?string $notes = null,
    ): self {
        return new self(null, $companyId, $employeeId, $equipmentId, $role, $startsAt, null, $notes);
    }

    public static function reconstitute(
        int $id,
        int $companyId,
        int $employeeId,
        int $equipmentId,
        string $role,
        DateTimeImmutable $startsAt,
        ?DateTimeImmutable $endsAt,
        ?string $notes,
    ): self {
        if ($id <= 0) {
            throw new DomainException('La identidad de la asignación debe ser válida.');
        }
        return new self($id, $companyId, $employeeId, $equipmentId, $role, $startsAt, $endsAt, $notes);
    }

    public function close(DateTimeImmutable $date): void
    {
        if ($this->endsAt !== null) {
            throw new DomainException('La asignación ya se encuentra cerrada.');
        }
        if ($date < $this->startsAt) {
            throw new DomainException('La fecha de cierre no puede ser anterior al inicio.');
        }
        $this->endsAt = $date;
    }

    public function id(): ?int { return $this->id; }
    public function companyId(): int { return $this->companyId; }
    public function employeeId(): int { return $this->employeeId; }
    public function equipmentId(): int { return $this->equipmentId; }
    public function role(): string { return $this->role; }
    public function startsAt(): DateTimeImmutable { return $this->startsAt; }
    public function endsAt(): ?DateTimeImmutable { return $this->endsAt; }
    public function notes(): ?string { return $this->notes; }
    public function isCurrent(): bool { return $this->endsAt === null; }

    private static function normalizeNotes(?string $notes): ?string
    {
        if ($notes === null) {
            return null;
        }
        $notes = trim($notes);
        if ($notes === '') {
            return null;
        }
        if (mb_strlen($notes) > 1000) {
            throw new DomainException('Las observaciones admiten hasta 1000 caracteres.');
        }
        return $notes;
    }
}
