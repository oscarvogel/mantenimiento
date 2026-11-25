<?php

declare(strict_types=1);

namespace App\Application\Trips;

use DomainException;

final readonly class CreateTripExpenseCategoryCommand
{
    public function __construct(private string $name)
    {
    }

    /** @param array<string,mixed> $input */
    public static function fromInput(array $input): self
    {
        return new self(trim((string) ($input['nombre'] ?? '')));
    }

    public function name(): string
    {
        return $this->name;
    }
}