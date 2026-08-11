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
            $objetivo = $plan->proximaFecha();
            if ($objetivo === null) {
                $faltantes[] = CriterioPlan::FECHA;
            } else {
                $actual = $fechaActual->setTime(0, 0, 0);
                $umbral = $objetivo->modify('-' . $plan->anticipacionDias() . ' days');
                $this->clasificar($actual >= $objetivo, $actual >= $umbral, CriterioPlan::FECHA, $vencidos, $proximos);
            }
        }

        if ($plan->usaKilometraje()) {
            $objetivo = $plan->proximoKm();
            if ($uso->kilometraje() === null || $objetivo === null) {
                $faltantes[] = CriterioPlan::KILOMETRAJE;
            } else {
                $actual = $uso->kilometraje();
                $umbral = $objetivo - $plan->anticipacionKm();
                $this->clasificar($actual >= $objetivo, $actual >= $umbral, CriterioPlan::KILOMETRAJE, $vencidos, $proximos);
            }
        }

        if ($plan->usaHorometro()) {
            $objetivo = $plan->proximasHorasDecimas();
            if ($uso->horasDecimas() === null || $objetivo === null) {
                $faltantes[] = CriterioPlan::HOROMETRO;
            } else {
                $actual = $uso->horasDecimas();
                $umbral = $objetivo - $plan->anticipacionHorasDecimas();
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
