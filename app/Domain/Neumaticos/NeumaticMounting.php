<?php

declare(strict_types=1);

namespace App\Domain\Neumaticos;

use DateTimeImmutable;
use DomainException;

/**
 * Una colocación del neumático en un equipo, desde que se monta hasta que se
 * extrae. Mientras no tiene extracción es la ubicación activa del neumático.
 *
 * El equipo se referencia por ID: pertenece a Registro de activos.
 */
final readonly class NeumaticMounting
{
    public function __construct(
        public int $equipmentId,
        public NeumaticPosition $position,
        public DateTimeImmutable $mountedAt,
        public int $mountedKm,
        public ?DateTimeImmutable $removedAt = null,
        public ?int $removedKm = null,
    ) {
        if ($equipmentId <= 0) {
            throw new DomainException('Indicá el equipo donde se coloca el neumático.');
        }
        self::assertKilometers($mountedKm, 'colocación');
        if (($removedAt === null) !== ($removedKm === null)) {
            throw new DomainException('La extracción necesita fecha y kilometraje.');
        }
        if ($removedAt !== null) {
            self::assertRemovalDate($mountedAt, $removedAt);
            self::assertKilometers((int) $removedKm, 'extracción');
        }
    }

    public function isActive(): bool
    {
        return $this->removedAt === null;
    }

    public function removedOn(DateTimeImmutable $at, int $kilometers): self
    {
        if (! $this->isActive()) {
            throw new DomainException('Esta colocación ya tiene extracción registrada.');
        }

        return new self($this->equipmentId, $this->position, $this->mountedAt, $this->mountedKm, $at, $kilometers);
    }

    /**
     * Null mientras el neumático sigue colocado: todavía no hay rendimiento.
     */
    public function performance(): ?NeumaticPerformance
    {
        if ($this->removedKm === null) {
            return null;
        }

        return NeumaticPerformance::between($this->mountedKm, $this->removedKm);
    }

    private static function assertRemovalDate(DateTimeImmutable $mountedAt, DateTimeImmutable $removedAt): void
    {
        if ($removedAt < $mountedAt) {
            throw new DomainException('La fecha de extracción no puede ser anterior a la de colocación.');
        }
    }

    private static function assertKilometers(int $kilometers, string $moment): void
    {
        if ($kilometers < 0) {
            throw new DomainException(sprintf('El kilometraje de %s no puede ser negativo.', $moment));
        }
    }
}
