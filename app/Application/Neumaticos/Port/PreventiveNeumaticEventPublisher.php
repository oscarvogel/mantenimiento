<?php

declare(strict_types=1);

namespace App\Application\Neumaticos\Port;

use DateTimeImmutable;

/**
 * Publicacion de los hechos del ciclo de vida del neumatico que el plan
 * preventivo necesita.
 *
 * Rotacion y recapado se planifican, no se inventan en la orden de trabajo: el
 * plan preventivo es quien decide cuando hay que actuar. Por eso Neumaticos
 * publica los hechos y PreventiveMaintenance decide que hacer con ellos.
 */
interface PreventiveNeumaticEventPublisher
{
    public function placed(int $companyId, int $equipmentId, int $neumaticId, DateTimeImmutable $at, int $kilometers): void;

    public function removed(int $companyId, int $equipmentId, int $neumaticId, DateTimeImmutable $at, int $kilometers): void;

    public function retreaded(int $companyId, int $neumaticId, DateTimeImmutable $at): void;
}
