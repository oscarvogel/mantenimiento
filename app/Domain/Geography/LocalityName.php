<?php

declare(strict_types=1);

namespace App\Domain\Geography;

final class LocalityName
{
    private const MIN_LENGTH = 2;
    private const MAX_LENGTH = 120;

    private function __construct(private readonly string $value)
    {
    }

    public static function fromString(string $raw): self
    {
        $name = trim(preg_replace('/\s+/u', ' ', $raw) ?? '');
        if (mb_strlen($name) < self::MIN_LENGTH) {
            throw new InvalidLocalityInput('Escribí el nombre de la localidad (mínimo 2 letras).');
        }
        if (mb_strlen($name) > self::MAX_LENGTH) {
            throw new InvalidLocalityInput('El nombre de la localidad no puede superar los 120 caracteres.');
        }

        return new self($name);
    }

    public function value(): string
    {
        return $this->value;
    }
}
