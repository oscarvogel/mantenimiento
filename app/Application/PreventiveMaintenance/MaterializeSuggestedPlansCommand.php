<?php

declare(strict_types=1);

namespace App\Application\PreventiveMaintenance;

use App\Application\Identity\ActorContext;

final readonly class MaterializeSuggestedPlansCommand
{
    /** @param list<PlanTemplateSelection> $selections */
    public function __construct(
        public ActorContext $actor,
        public int $equipmentId,
        public array $selections,
    ) {
    }
}
