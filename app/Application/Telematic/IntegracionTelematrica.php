<?php

declare(strict_types=1);

namespace App\Application\Telematic;

use InvalidArgumentException;

/**
 * Integración de telemetría activa, sin credenciales.
 *
 * Deliberadamente no lleva endpoint ni token: eso se resuelve y se descifra
 * dentro de Infrastructure. La capa de aplicación sólo necesita saber a quién
 * preguntarle y de quién es el dato.
 */
final readonly class IntegracionTelematrica
{
    public function __construct(
        private int $id,
        private int $companyId,
        private string $provider,
        private string $name,
    ) {
        if ($id <= 0 || $companyId <= 0) {
            throw new InvalidArgumentException('La integración requiere identificador y empresa válidos.');
        }
        if (trim($provider) === '' || trim($name) === '') {
            throw new InvalidArgumentException('La integración requiere proveedor y nombre.');
        }
    }

    public function id(): int
    {
        return $this->id;
    }

    public function companyId(): int
    {
        return $this->companyId;
    }

    public function provider(): string
    {
        return $this->provider;
    }

    public function name(): string
    {
        return $this->name;
    }
}