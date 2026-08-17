<?php

declare(strict_types=1);

namespace App\Domain\Measurement;

use DomainException;

final class UsageMeasurement
{
    private const MAX_HOURS_TENTHS = 999_999_999_999;

    private function __construct(
        private readonly ?int $kilometers,
        private readonly ?int $hoursTenths,
    ) {
    }

    public static function from(?int $kilometers, int|float|string|null $hours): self
    {
        if ($kilometers !== null && $kilometers < 0) {
            throw new DomainException('El kilometraje no puede ser negativo.');
        }

        $hoursTenths = self::parseHours($hours);
        if ($kilometers === null && $hoursTenths === null) {
            throw new DomainException('La lectura debe informar kilometraje, horómetro o ambos.');
        }

        return new self($kilometers, $hoursTenths);
    }

    public static function parseHours(int|float|string|null $hours): ?int
    {
        if ($hours === null || (is_string($hours) && trim($hours) === '')) {
            return null;
        }

        // La coma y el punto son separadores decimales equivalentes. No se
        // interpretan separadores de miles ni formatos con ambos separadores.
        $normalized = str_replace(',', '.', trim((string) $hours));
        if (! preg_match('/^\d+(?:\.\d)?$/', $normalized)) {
            throw new DomainException('El horómetro debe ser un número positivo con un decimal como máximo. Podés usar coma o punto.');
        }

        [$whole, $decimal] = array_pad(explode('.', $normalized, 2), 2, '0');
        if (strlen($whole) > 11) {
            throw new DomainException('El horómetro excede el máximo admitido.');
        }

        $tenths = ((int) $whole * 10) + (int) $decimal;
        if ($tenths > self::MAX_HOURS_TENTHS) {
            throw new DomainException('El horómetro excede el máximo admitido.');
        }

        return $tenths;
    }

    public function kilometers(): ?int
    {
        return $this->kilometers;
    }

    public function hoursTenths(): ?int
    {
        return $this->hoursTenths;
    }

    public function hours(): ?string
    {
        if ($this->hoursTenths === null) {
            return null;
        }

        return intdiv($this->hoursTenths, 10) . '.' . ($this->hoursTenths % 10);
    }

    public function hasKilometers(): bool
    {
        return $this->kilometers !== null;
    }

    public function hasHours(): bool
    {
        return $this->hoursTenths !== null;
    }
}
