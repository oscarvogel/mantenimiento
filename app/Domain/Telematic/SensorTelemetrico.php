<?php

declare(strict_types=1);

namespace App\Domain\Telematic;

use InvalidArgumentException;

/** Una definición de sensor y el valor que informó la captura actual. */
final readonly class SensorTelemetrico
{
    public function __construct(
        private string $id,
        private string $etiqueta,
        private string $tipo,
        private string $unidad,
        private ?float $valor,
    ) {
        if (trim($id) === '' || trim($etiqueta) === '') {
            throw new InvalidArgumentException('El sensor telemétrico requiere identificador y etiqueta.');
        }
    }

    public function id(): string
    {
        return $this->id;
    }

    public function etiqueta(): string
    {
        return $this->etiqueta;
    }

    public function tipo(): string
    {
        return $this->tipo;
    }

    public function unidad(): string
    {
        return $this->unidad;
    }

    public function valor(): ?float
    {
        return $this->valor;
    }
}
