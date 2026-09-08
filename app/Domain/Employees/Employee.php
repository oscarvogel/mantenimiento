<?php

declare(strict_types=1);

namespace App\Domain\Employees;

use DateTimeImmutable;
use DomainException;

final class Employee
{
    private function __construct(
        private readonly ?int $id,
        private readonly int $companyId,
        private string $firstName,
        private string $lastName,
        private ?string $document,
        private ?string $cuil,
        private ?string $employeeNumber,
        private ?string $phone,
        private ?string $email,
        private ?DateTimeImmutable $hiredAt,
        private ?string $notes,
        private bool $active,
        private ?DateTimeImmutable $terminatedAt,
        private ?string $terminationReason,
        private bool $importedIncomplete,
    ) {
        if ($companyId <= 0) {
            throw new DomainException('La empresa del empleado debe ser válida.');
        }

        $this->firstName = self::requiredText($firstName, 100, 'El nombre');
        $this->lastName = self::optionalText($lastName, 100) ?? '';
        $this->document = self::optionalText($document, 30);
        $this->cuil = self::optionalText($cuil, 30);
        $this->employeeNumber = self::optionalText($employeeNumber, 50);
        $this->phone = self::optionalText($phone, 50);
        $this->email = self::optionalEmail($email);
        $this->notes = self::optionalText($notes, 1000);
        $this->terminationReason = self::optionalText($terminationReason, 500);

        if (! $active && $terminatedAt === null) {
            throw new DomainException('Un empleado dado de baja debe tener fecha de baja.');
        }
    }

    public static function create(
        int $companyId,
        string $firstName,
        string $lastName = '',
        ?string $document = null,
        ?string $cuil = null,
        ?string $employeeNumber = null,
        ?string $phone = null,
        ?string $email = null,
        ?DateTimeImmutable $hiredAt = null,
        ?string $notes = null,
        bool $importedIncomplete = false,
    ): self {
        return new self(
            null,
            $companyId,
            $firstName,
            $lastName,
            $document,
            $cuil,
            $employeeNumber,
            $phone,
            $email,
            $hiredAt,
            $notes,
            true,
            null,
            null,
            $importedIncomplete,
        );
    }

    public static function reconstitute(
        int $id,
        int $companyId,
        string $firstName,
        string $lastName,
        ?string $document,
        ?string $cuil,
        ?string $employeeNumber,
        ?string $phone,
        ?string $email,
        ?DateTimeImmutable $hiredAt,
        ?string $notes,
        bool $active,
        ?DateTimeImmutable $terminatedAt,
        ?string $terminationReason,
        bool $importedIncomplete,
    ): self {
        if ($id <= 0) {
            throw new DomainException('La identidad del empleado debe ser válida.');
        }

        return new self(
            $id,
            $companyId,
            $firstName,
            $lastName,
            $document,
            $cuil,
            $employeeNumber,
            $phone,
            $email,
            $hiredAt,
            $notes,
            $active,
            $terminatedAt,
            $terminationReason,
            $importedIncomplete,
        );
    }

    public function updateProfile(
        string $firstName,
        string $lastName = '',
        ?string $document = null,
        ?string $cuil = null,
        ?string $employeeNumber = null,
        ?string $phone = null,
        ?string $email = null,
        ?DateTimeImmutable $hiredAt = null,
        ?string $notes = null,
    ): void {
        if (! $this->active) {
            throw new DomainException('No se puede editar un empleado dado de baja.');
        }

        $this->firstName = self::requiredText($firstName, 100, 'El nombre');
        $this->lastName = self::optionalText($lastName, 100) ?? '';
        $this->document = self::optionalText($document, 30);
        $this->cuil = self::optionalText($cuil, 30);
        $this->employeeNumber = self::optionalText($employeeNumber, 50);
        $this->phone = self::optionalText($phone, 50);
        $this->email = self::optionalEmail($email);
        $this->hiredAt = $hiredAt;
        $this->notes = self::optionalText($notes, 1000);
    }

    public function terminate(DateTimeImmutable $date, string $reason): void
    {
        if (! $this->active) {
            throw new DomainException('El empleado ya se encuentra dado de baja.');
        }

        $reason = self::requiredText($reason, 500, 'El motivo de baja');
        $this->active = false;
        $this->terminatedAt = $date;
        $this->terminationReason = $reason;
    }

    public function id(): ?int { return $this->id; }
    public function companyId(): int { return $this->companyId; }
    public function firstName(): string { return $this->firstName; }
    public function lastName(): string { return $this->lastName; }
    public function fullName(): string { return trim($this->firstName . ' ' . $this->lastName); }
    public function document(): ?string { return $this->document; }
    public function cuil(): ?string { return $this->cuil; }
    public function employeeNumber(): ?string { return $this->employeeNumber; }
    public function phone(): ?string { return $this->phone; }
    public function email(): ?string { return $this->email; }
    public function hiredAt(): ?DateTimeImmutable { return $this->hiredAt; }
    public function notes(): ?string { return $this->notes; }
    public function isActive(): bool { return $this->active; }
    public function terminatedAt(): ?DateTimeImmutable { return $this->terminatedAt; }
    public function terminationReason(): ?string { return $this->terminationReason; }
    public function isImportedIncomplete(): bool { return $this->importedIncomplete; }

    private static function requiredText(string $value, int $max, string $label): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
        if ($value === '' || mb_strlen($value) > $max) {
            throw new DomainException($label . ' es obligatorio y admite hasta ' . $max . ' caracteres.');
        }
        return $value;
    }

    private static function optionalText(?string $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > $max) {
            throw new DomainException('El valor supera el máximo permitido de ' . $max . ' caracteres.');
        }
        return $value;
    }

    private static function optionalEmail(?string $email): ?string
    {
        $email = self::optionalText($email, 150);
        if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new DomainException('El email del empleado no es válido.');
        }
        return $email;
    }
}
