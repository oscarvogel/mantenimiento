<?php

declare(strict_types=1);

namespace App\Domain\PreventiveMaintenance;

use DateTimeImmutable;
use DomainException;

final class EvaluadorVencimiento
{
    public function evaluar(PlanMantenimiento $plan, UsoActual $uso, DateTimeImmutable $fechaActual): EvaluacionPlan
    {
        if (! $plan->activo()) {
            throw new DomainException('No se puede evaluar un plan inactivo.');
        }

        $vencidos = [];
        $proximos = [];
        $faltantes = [];

        if ($plan->usaFecha()) {
            $actual   = $fechaActual->setTime(0, 0, 0);
            $objetivo = $plan->proximaFecha();
            $umbral   = $objetivo->modify('-' . $plan->anticipacionDias() . ' days');
            $this->clasificar($actual >= $objetivo, $actual >= $umbral, CriterioPlan::FECHA, $vencidos, $proximos);
        }

        if ($plan->usaKilometraje()) {
            if ($uso->kilometraje() === null) {
                $faltantes[] = CriterioPlan::KILOMETRAJE;
            } else {
                $actual   = $uso->kilometraje();
                $objetivo = $plan->proximoKm();
                $umbral   = $objetivo - $plan->anticipacionKm();
                $this->clasificar($actual >= $objetivo, $actual >= $umbral, CriterioPlan::KILOMETRAJE, $vencidos, $proximos);
            }
        }

        if ($plan->usaHorometro()) {
            if ($uso->horasDecimas() === null) {
                $faltantes[] = CriterioPlan::HOROMETRO;
            } else {
                $actual   = $uso->horasDecimas();
                $objetivo = $plan->proximasHorasDecimas();
                $umbral   = $objetivo - $plan->anticipacionHorasDecimas();
                $this->clasificar($actual >= $objetivo, $actual >= $umbral, CriterioPlan::HOROMETRO, $vencidos, $proximos);
            }
        }

        $estado = match (true) {
            $faltantes !== [] => EstadoPlan::SIN_DATOS,
            $vencidos !== []  => EstadoPlan::VENCIDO,
            $proximos !== []  => EstadoPlan::PROXIMO,
            default           => EstadoPlan::AL_DIA,
        };

        return new EvaluacionPlan($estado, $vencidos, $proximos, $faltantes);
    }

    /**
     * @param list<CriterioPlan> $vencidos
     * @param list<CriterioPlan> $proximos
     */
    private function clasificar(
        bool $estaVencido,
        bool $estaProximo,
        CriterioPlan $criterio,
        array &$vencidos,
        array &$proximos,
    ): void {
        if ($estaVencido) {
            $vencidos[] = $criterio;
        } elseif ($estaProximo) {
            $proximos[] = $criterio;
        }
    }
}
