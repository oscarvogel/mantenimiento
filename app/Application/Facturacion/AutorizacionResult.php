<?php

declare(strict_types=1);

namespace App\Application\Facturacion;

use DateTimeImmutable;

/**
 * Resultado de una operacion ante ARCA.
 *
 * Un rechazo no es una excepcion: es un resultado valido con codigo y mensaje,
 * porque el sistema lo tiene que mostrar al operador y guardarlo en la traza.
 */
final readonly class AutorizacionResult
{
    public function __construct(
        public bool $aprobado,
        public ?string $cae = null,
        public ?DateTimeImmutable $caeVencimiento = null,
        public ?string $codigo = null,
        public ?string $mensaje = null,
    ) {
    }

    public function rechazado(): bool
    {
        return ! $this->aprobado;
    }
}
