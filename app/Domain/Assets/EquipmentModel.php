<?php

declare(strict_types=1);

namespace App\Domain\Assets;

use DomainException;

final class EquipmentModel
{
    private function __construct(
        private readonly ?int $id,
        private readonly int $companyId,
        private readonly int $brandId,
        private readonly int $equipmentTypeId,
        private string $name,
        private bool $active,
    ) {
        if ($companyId <= 0 || $brandId <= 0 || $equipmentTypeId <= 0) {
            throw new DomainException('La empresa, marca y tipo del modelo deben ser válidos.');
        }
        $this->name = self::normalizeName($name);
    }

    public static function create(int $companyId, int $brandId, int $equipmentTypeId, string $name): self
    {
        return new self(null, $companyId, $brandId, $equipmentTypeId, $name, true);
    }

    public static function reconstitute(
        int $id,
        int $companyId,
        int $brandId,
        int $equipmentTypeId,
        string $name,
        bool $active,
    ): self {
        if ($id <= 0) {
            throw new DomainException('La identidad del modelo debe ser válida.');
        }

        return new self($id, $companyId, $brandId, $equipmentTypeId, $name, $active);
    }

    public function rename(string $name): void
    {
        if (! $this->active) {
            throw new DomainException('No se puede editar un modelo inactivo.');
        }
        $this->name = self::normalizeName($name);
    }

    public function inactivate(): void
    {
        if (! $this->active) {
            throw new DomainException('El modelo ya se encuentra inactivo.');
        }
        $this->active = false;
    }

    public function assertCompatible(int $brandId, int $equipmentTypeId): void
    {
        if (! $this->active || $this->brandId !== $brandId || $this->equipmentTypeId !== $equipmentTypeId) {
            throw new DomainException('El modelo no está activo o no corresponde a la marca y tipo seleccionados.');
        }
    }

    public function id(): ?int { return $this->id; }
    public function companyId(): int { return $this->companyId; }
    public function brandId(): int { return $this->brandId; }
    public function equipmentTypeId(): int { return $this->equipmentTypeId; }
    public function name(): string { return $this->name; }
    public function isActive(): bool { return $this->active; }

    private static function normalizeName(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        if ($name === '' || mb_strlen($name) > 100) {
            throw new DomainException('El nombre del modelo es obligatorio y admite hasta 100 caracteres.');
        }

        return $name;
    }
}
