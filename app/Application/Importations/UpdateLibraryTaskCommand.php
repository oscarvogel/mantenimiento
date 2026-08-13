<?php

declare(strict_types=1);

namespace App\Application\Importations;

final readonly class UpdateLibraryTaskCommand
{
    public function __construct(
        public int $taskId,
        public int $serviceTypeId,
        public string $name,
        public ?string $description,
        public ?string $procedure,
        public ?int $durationMinutes,
        public bool $requiresPart,
        public bool $requiresControl,
        public bool $requiresPhoto,
        public bool $active,
        public int $order,
        public bool $mandatory,
        public ?string $observations,
    ) {
    }
}