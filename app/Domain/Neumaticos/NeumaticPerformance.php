<?php

declare(strict_types=1);

namespace App\Domain\Neumaticos;

/**
 * Rendimiento de una colocación: kilómetros recorridos entre la colocación y
 * la extracción.
 *
 * Es el indicador central del módulo, y por eso no se inventa: si el
 * kilometraje de extracción es menor que el de colocación, el número no es
 * confiable y se informa `sin datos suficientes` en lugar de un valor.
 */
final readonly class NeumaticPerformance
{
    public const INSUFFICIENT_DATA = 'sin datos suficientes';

    private function __construct(
        public ?int $kilometers,
        public bool $reliable,
    ) {
    }

    public static function between(int $mountedKm, int $removedKm): self
    {
        if ($removedKm < $mountedKm) {
            return new self(null, false);
        }

        return new self($removedKm - $mountedKm, true);
    }

    public function describe(): string
    {
        return $this->reliable ? number_format((int) $this->kilometers, 0, ',', '.') . ' km' : self::INSUFFICIENT_DATA;
    }
}
