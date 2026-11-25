<?php

declare(strict_types=1);

namespace App\Application\Neumaticos;

use DateTimeImmutable;

final readonly class RegisterNeumaticCommand
{
    public function __construct(
        public int $branchId,
        public string $number,
        public ?int $brandId = null,
        public ?int $modelId = null,
        public ?int $coverTypeId = null,
        public string $size = '',
        public ?int $manufactureYear = null,
        public string $initialStatus = 'NUEVA',
        public ?DateTimeImmutable $registeredAt = null,
    ) {
    }
}
