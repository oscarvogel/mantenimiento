<?php

declare(strict_types=1);

namespace App\Application\Neumaticos;

use DateTimeImmutable;

final readonly class MountNeumaticCommand
{
    public function __construct(
        public int $neumaticId,
        public int $equipmentId,
        public int $axle,
        public string $side,
        public string $slot,
        public int $kilometers,
        public ?string $button = null,
        public ?DateTimeImmutable $at = null,
    ) {
    }
}
