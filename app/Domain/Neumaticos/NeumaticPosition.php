<?php

declare(strict_types=1);

namespace App\Domain\Neumaticos;

use DomainException;

/**
 * Lugar físico que ocupa un neumático en un equipo: lugar (eje), lado,
 * posición y, cuando corresponde, botón.
 *
 * Los largos máximos son los del sistema anterior
 * (`ubicacion_de_los_neumaticos`), para que la migración no recorte datos.
 * Los valores no se cierran a un catálogo hasta conocer la codificación real.
 */
final readonly class NeumaticPosition
{
    public string $place;
    public string $side;
    public string $slot;
    public ?string $button;

    public function __construct(string $place, string $side, string $slot, ?string $button = null)
    {
        $this->place = self::required($place, 'el lugar', 5);
        $this->side = self::required($side, 'el lado', 1);
        $this->slot = self::required($slot, 'la posición', 1);

        $button = $button === null ? '' : mb_strtoupper(trim($button));
        if (mb_strlen($button) > 10) {
            throw new DomainException('El botón no puede superar los 10 caracteres.');
        }
        $this->button = $button === '' ? null : $button;
    }

    public function equals(self $other): bool
    {
        return $this->place === $other->place
            && $this->side === $other->side
            && $this->slot === $other->slot
            && $this->button === $other->button;
    }

    private static function required(string $value, string $name, int $maxLength): string
    {
        $value = mb_strtoupper(trim($value));
        if ($value === '') {
            throw new DomainException(sprintf('Indicá %s del neumático en el equipo.', $name));
        }
        if (mb_strlen($value) > $maxLength) {
            throw new DomainException(sprintf('%s no puede superar los %d caracteres.', ucfirst($name), $maxLength));
        }

        return $value;
    }
}
