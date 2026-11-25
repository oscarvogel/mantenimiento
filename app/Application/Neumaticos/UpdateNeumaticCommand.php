<?php

declare(strict_types=1);

namespace App\Application\Neumaticos;

/**
 * Corrección de los datos descriptivos. No lleva número: es la identidad del
 * neumático y no cambia.
 */
final readonly class UpdateNeumaticCommand
{
    public function __construct(
        public int $neumaticId,
        public ?int $brandId,
        public ?int $modelId,
        public ?int $coverTypeId,
        public string $size,
        public ?int $manufactureYear,
    ) {
    }
}
