<?php

declare(strict_types=1);

namespace App\Domain\Neumaticos;

use DomainException;

/**
 * Número del neumático dentro de la empresa: es su identidad de negocio, el
 * que está grabado o marcado en la cubierta, y no cambia una vez dado de alta.
 */
final readonly class NeumaticNumber
{
    /** Largo del número en el sistema anterior (`numero_neumatico character(20)`). */
    public const MAX_LENGTH = 20;

    public string $value;

    public function __construct(string $value)
    {
        $normalized = mb_strtoupper(trim((string) preg_replace('/\s+/u', ' ', $value)));
        if ($normalized === '') {
            throw new DomainException('Indicá el número del neumático.');
        }
        if (mb_strlen($normalized) > self::MAX_LENGTH) {
            throw new DomainException(sprintf('El número del neumático no puede superar los %d caracteres.', self::MAX_LENGTH));
        }

        $this->value = $normalized;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
