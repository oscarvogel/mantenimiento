<?php

declare(strict_types=1);

namespace App\Domain\PreventiveMaintenance;

final readonly class EvaluacionPlan
{
    /**
     * @param list<CriterioPlan> $vencidos
     * @param list<CriterioPlan> $proximos
     * @param list<CriterioPlan> $faltantes
     */
    public function __construct(
        private EstadoPlan $estado,
        private array $vencidos,
        private array $proximos,
        private array $faltantes,
    ) {
    }

    public function estado(): EstadoPlan
    {
        return $this->estado;
    }

    /** @return list<CriterioPlan> */
    public function vencidos(): array
    {
        return $this->vencidos;
    }

    /** @return list<CriterioPlan> */
    public function proximos(): array
    {
        return $this->proximos;
    }

    /** @return list<CriterioPlan> */
    public function faltantes(): array
    {
        return $this->faltantes;
    }

    /** @return list<string> */
    public function criteriosDisparadores(): array
    {
        $criterios = $this->estado === EstadoPlan::VENCIDO ? $this->vencidos : $this->proximos;

        return array_map(static fn (CriterioPlan $criterio): string => $criterio->value, $criterios);
    }
}
