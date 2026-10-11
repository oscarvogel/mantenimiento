<?php

declare(strict_types=1);

namespace App\Domain\Telematic;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Una fuente de telemetría vinculada a un equipo, con su última señal.
 *
 * Un equipo puede tener varias: un mismo camión puede reportar a Wialon y a
 * Gestya al mismo tiempo. Por eso la fuente se modela sola y la agregación
 * vive en `CoberturaEquipo`.
 */
final readonly class FuenteSenal
{
    public function __construct(
        private string $integrationId,
        private string $provider,
        private string $integrationName,
        private string $unidadExterna,
        private string $rol,
        private ?EstadoSenal $estado,
    ) {
        if (trim($integrationId) === '' || trim($provider) === '' || trim($unidadExterna) === '') {
            throw new InvalidArgumentException('La fuente requiere integración, proveedor y unidad externa.');
        }
    }

    public function integrationId(): string
    {
        return $this->integrationId;
    }

    public function provider(): string
    {
        return $this->provider;
    }

    public function integrationName(): string
    {
        return $this->integrationName;
    }

    public function unidadExterna(): string
    {
        return $this->unidadExterna;
    }

    public function rol(): string
    {
        return $this->rol;
    }

    public function estado(): EstadoSenal
    {
        // Una fuente vinculada que el proveedor no devolvió se trata como una
        // señal que nunca llegó. Es indistinguible de un vínculo roto, y por
        // eso `EstadoSenal` no la marca como estancada: el agregado decide.
        return $this->estado ?? new EstadoSenal($this->unidadExterna, null);
    }

    public function instantanea(): ?InstantaneaEquipo
    {
        return $this->estado?->instantanea();
    }

    public function posicion(): ?Posicion
    {
        return $this->estado?->instantanea()?->posicion();
    }

    public function reportaEn(): ?DateTimeImmutable
    {
        return $this->estado?->ultimaSenalEn();
    }

    public function estaEstancada(DateTimeImmutable $ahora, int $umbralHoras): bool
    {
        return $this->estado?->estaEstancada($ahora, $umbralHoras) ?? false;
    }

    public function minutosSinSenal(DateTimeImmutable $ahora): ?int
    {
        return $this->estado?->minutosSinSenal($ahora);
    }
}