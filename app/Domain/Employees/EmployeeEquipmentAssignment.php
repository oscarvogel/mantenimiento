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
        private ?string $closureReason = null,
        private ?string $closureNotes = null,
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
        $this->closureReason = self::normalizeClosureReason($closureReason);
        $this->closureNotes = self::normalizeNotes($closureNotes);
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
        ?string $closureReason = null,
        ?string $closureNotes = null,
    ): self {
        if ($id <= 0) {
            throw new DomainException('La identidad de la asignación debe ser válida.');
        }
        return new self($id, $companyId, $employeeId, $equipmentId, $role, $startsAt, $endsAt, $notes, $closureReason, $closureNotes);
    }

    /**
     * Cierra la asignación conservando el período y registrando el motivo.
     *
     * El motivo es obligatorio: la desvinculación de un chofer es una operación
     * sensible que debe poder auditarse. Las observaciones son opcionales y no
     * reemplazan las notas originales de la asignación.
     */
    public function close(DateTimeImmutable $date, string $reason, ?string $notes = null): void
    {
        if ($this->endsAt !== null) {
            throw new DomainException('La asignación ya se encuentra cerrada.');
        }
        if ($date < $this->startsAt) {
            throw new DomainException('La fecha de cierre no puede ser anterior al inicio.');
        }

        $this->endsAt = $date;
        $this->closureReason = self::normalizeClosureReason($reason);
        $this->closureNotes = self::normalizeNotes($notes);
    }

    public function id(): ?int { return $this->id; }
    public function companyId(): int { return $this->companyId; }
    public function employeeId(): int { return $this->employeeId; }
    public function equipmentId(): int { return $this->equipmentId; }
    public function role(): string { return $this->role; }
    public function startsAt(): DateTimeImmutable { return $this->startsAt; }
    public function endsAt(): ?DateTimeImmutable { return $this->endsAt; }
    public function notes(): ?string { return $this->notes; }
    public function closureReason(): ?string { return $this->closureReason; }
    public function closureNotes(): ?string { return $this->closureNotes; }
    public function isCurrent(): bool { return $this->endsAt === null; }

    private static function normalizeClosureReason(?string $reason): ?string
    {
        if ($reason === null) {
            return null;
        }

        $reason = trim(preg_replace('/\s+/u', ' ', $reason) ?? '');
        if ($reason === '') {
            throw new DomainException('El motivo de cierre es obligatorio.');
        }
        if (mb_strlen($reason) < 5) {
            throw new DomainException('El motivo de cierre debe tener al menos 5 caracteres.');
        }
        if (mb_strlen($reason) > 255) {
            throw new DomainException('El motivo de cierre admite hasta 255 caracteres.');
        }

        return $reason;
    }

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
