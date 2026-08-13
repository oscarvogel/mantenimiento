<?php

declare(strict_types=1);

namespace App\Application\PreventiveMaintenance;

use App\Application\Identity\ActorContext;
use DateTimeImmutable;

final readonly class ActualizarPlanCommand
{
    public function __construct(
        public ActorContext $actor,
        public int $companyId,
        public int $planId,
        public ?int $intervalKm,
        public ?int $intervalHoursTenths,
        public ?int $intervalDays,
        public ?int $warningKm,
        public ?int $warningHoursTenths,
        public ?int $warningDays,
        public ?int $baseKm = null,
        public ?int $baseHoursTenths = null,
        public ?DateTimeImmutable $baseDate = null,
        public string $priority = 'MEDIA',
        public ?string $notes = null,
    ) {
    }
}