<?php

declare(strict_types=1);

namespace App\Application\Trips;

use DomainException;

final readonly class UpdateTripLegCommand
{
    public function __construct(
        public int $legId,
        public AddTripLegCommand $leg,
    ) {
    }

    /** @param array<string,mixed> $input */
    public static function fromInput(array $input): self
    {
        $legId = filter_var($input['leg_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($legId === false) {
            throw new DomainException('El tramo a editar no es válido.');
        }

        return new self((int) $legId, AddTripLegCommand::fromInput($input));
    }
}
