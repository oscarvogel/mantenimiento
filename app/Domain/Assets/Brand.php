<?php

declare(strict_types=1);

namespace App\Domain\Assets;

use DomainException;

final class Brand
{
    private function __construct(
        private readonly ?int $id,
        private readonly int $companyId,
        private string $name,
        private bool $active,
    ) {
        if ($companyId <= 0) {
            throw new DomainException('La empresa de la marca debe ser válida.');
        }
        $this->name = self::normalizeName($name);
    }

    public static function create(int $companyId, string $name): self
    {
        return new self(null, $companyId, $name, true);
    }

    public static function reconstitute(int $id, int $companyId, string $name, bool $active): self
    {
        if ($id <= 0) {
            throw new DomainException('La identidad de la marca debe ser válida.');
        }

        return new self($id, $companyId, $name, $active);
    }

    public function rename(string $name): void
    {
        if (! $this->active) {
            throw new DomainException('No se puede editar una marca inactiva.');
        }
        $this->name = self::normalizeName($name);
    }

    public function inactivate(): void
    {
        if (! $this->active) {
            throw new DomainException('La marca ya se encuentra inactiva.');
        }
        $this->active = false;
    }

    public function id(): ?int { return $this->id; }
    public function companyId(): int { return $this->companyId; }
    public function name(): string { return $this->name; }
    public function isActive(): bool { return $this->active; }

    private static function normalizeName(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        if ($name === '' || mb_strlen($name) > 100) {
            throw new DomainException('El nombre de la marca es obligatorio y admite hasta 100 caracteres.');
        }

        return $name;
    }
}
