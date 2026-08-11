<?php

declare(strict_types=1);

namespace App\Application\PreventiveMaintenance;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class PlanTemplateSelection
{
    public function __construct(
        public int $templateItemId,
        public ?int $baseKm = null,
        public ?int $baseHoursTenths = null,
        public ?DateTimeImmutable $baseDate = null,
    ) {
        if ($templateItemId <= 0 || ($baseKm !== null && $baseKm < 0) || ($baseHoursTenths !== null && $baseHoursTenths < 0)) {
            throw new InvalidArgumentException('La seleccion o sus bases historicas no son validas.');
        }
    }
}
