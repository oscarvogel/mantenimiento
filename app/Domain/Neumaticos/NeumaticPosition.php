<?php

declare(strict_types=1);

namespace App\Domain\Neumaticos;

use DomainException;

/**
 * Lugar físico que ocupa un neumático en un equipo: eje, lado y si es la
 * rueda exterior, la interior o la única del lado. El botón es opcional.
 *
 * Decisión del 25/11/2026: los valores se eligen de listas cerradas para que
 * no haya dos formas de escribir la misma posición. Se guardan en las columnas
 * del sistema anterior (lugar, lado, posición, botón): lugar = eje.
 */
final readonly class NeumaticPosition
{
    public const MAX_AXLE = 10;

    public const LEFT = 'I';
    public const RIGHT = 'D';

    public const OUTER = 'E';
    public const INNER = 'I';
    public const SINGLE = 'S';

    /** @var array<string, string> */
    public const SIDES = [self::LEFT => 'izquierdo', self::RIGHT => 'derecho'];

    /** @var array<string, string> */
    public const SLOTS = [self::OUTER => 'exterior', self::INNER => 'interior', self::SINGLE => 'simple'];

    public int $axle;
    public string $side;
    public string $slot;
    public ?string $button;

    public function __construct(int $axle, string $side, string $slot, ?string $button = null)
    {
        if ($axle < 1 || $axle > self::MAX_AXLE) {
            throw new DomainException(sprintf('El eje tiene que estar entre 1 y %d.', self::MAX_AXLE));
        }
        $side = mb_strtoupper(trim($side));
        if (! array_key_exists($side, self::SIDES)) {
            throw new DomainException('Elegí el lado: izquierdo o derecho.');
        }
        $slot = mb_strtoupper(trim($slot));
        if (! array_key_exists($slot, self::SLOTS)) {
            throw new DomainException('Elegí si es la rueda exterior, la interior o la única del lado.');
        }
        $button = $button === null ? '' : mb_strtoupper(trim($button));
        if (mb_strlen($button) > 10) {
            throw new DomainException('El botón no puede superar los 10 caracteres.');
        }

        $this->axle = $axle;
        $this->side = $side;
        $this->slot = $slot;
        $this->button = $button === '' ? null : $button;
    }

    /**
     * Reconstruye la posición desde las columnas guardadas (lugar = eje).
     */
    public static function fromStored(string $place, string $side, string $slot, ?string $button): self
    {
        $place = trim($place);
        if (! ctype_digit($place)) {
            throw new DomainException('La posición guardada no tiene un eje válido.');
        }

        return new self((int) $place, $side, $slot, $button);
    }

    /**
     * Dos ruedas no pueden ocupar el mismo eje, lado y lugar: el botón no
     * distingue posiciones, solo las describe.
     */
    public function sameSpotAs(self $other): bool
    {
        return $this->axle === $other->axle && $this->side === $other->side && $this->slot === $other->slot;
    }

    public function equals(self $other): bool
    {
        return $this->sameSpotAs($other) && $this->button === $other->button;
    }

    public function label(): string
    {
        return $this->button === null ? $this->spotLabel() : $this->spotLabel() . ' · botón ' . $this->button;
    }

    /**
     * El lugar sin el botón: es lo que puede estar ocupado.
     */
    public function spotLabel(): string
    {
        return sprintf('Eje %d · %s · %s', $this->axle, self::SIDES[$this->side], self::SLOTS[$this->slot]);
    }
}
