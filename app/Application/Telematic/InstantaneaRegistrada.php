<?php

declare(strict_types=1);

namespace App\Application\Telematic;

use App\Domain\Telematic\InstantaneaEquipo;
use InvalidArgumentException;

/**
 * Instantánea con su contexto de alcance: a qué empresa, a qué equipo y a qué
 * fuente pertenece. El objeto de dominio no lleva eso porque el dominio no
 * sabe de empresas ni de integraciones.
 */
final readonly class InstantaneaRegistrada
{
    public function __construct(
        private int $companyId,
        private ?int $branchId,
        private int $equipmentId,
        private int $integrationId,
        private string $provider,
        private string $externalUnitId,
        private InstantaneaEquipo $snapshot,
        private string $registeredAt,
    ) {
        if ($companyId <= 0 || $equipmentId <= 0 || $integrationId <= 0) {
            throw new InvalidArgumentException('La instantánea registrada requiere empresa, equipo e integración válidos.');
        }
        if (trim($this->externalUnitId) === '' || trim($this->provider) === '') {
            throw new InvalidArgumentException('La instantánea registrada requiere proveedor y unidad externa.');
        }
    }

    public function companyId(): int
    {
        return $this->companyId;
    }

    public function branchId(): ?int
    {
        return $this->branchId;
    }

    public function equipmentId(): int
    {
        return $this->equipmentId;
    }

    public function integrationId(): int
    {
        return $this->integrationId;
    }

    public function provider(): string
    {
        return $this->provider;
    }

    public function externalUnitId(): string
    {
        return $this->externalUnitId;
    }

    public function snapshot(): InstantaneaEquipo
    {
        return $this->snapshot;
    }

    public function registeredAt(): string
    {
        return $this->registeredAt;
    }
}