<?php

declare(strict_types=1);

namespace App\Domain\PreventiveMaintenance;

use DateTimeImmutable;
use DomainException;
use InvalidArgumentException;

final class AvisoPlan
{
    /** @param list<string> $criteriosDisparadores */
    private function __construct(
        private ?int $id,
        private readonly int $empresaId,
        private readonly int $planId,
        private readonly int $equipoId,
        private readonly string $claveCiclo,
        private readonly array $criteriosDisparadores,
        private readonly DateTimeImmutable $fechaDeteccion,
        private EstadoGestionAviso $estadoGestion,
        private ?DateTimeImmutable $fechaResolucion,
        private ?string $motivoResolucion,
    ) {
        if (($id !== null && $id <= 0) || $empresaId <= 0 || $planId <= 0 || $equipoId <= 0 || strlen($claveCiclo) !== 64) {
            throw new InvalidArgumentException('La identidad del aviso o su ciclo no es valida.');
        }

        if ($criteriosDisparadores === []) {
            throw new InvalidArgumentException('Un aviso vencido debe indicar al menos un criterio disparador.');
        }

        $validCriteria = array_map(static fn (CriterioPlan $criterion): string => $criterion->value, CriterioPlan::cases());
        if (array_diff($criteriosDisparadores, $validCriteria) !== []
            || count($criteriosDisparadores) !== count(array_unique($criteriosDisparadores))) {
            throw new InvalidArgumentException('Los criterios disparadores del aviso no son validos.');
        }
    }

    public static function paraPlanVencido(
        PlanMantenimiento $plan,
        EvaluacionPlan $evaluacion,
        DateTimeImmutable $fechaDeteccion,
    ): self {
        if ($plan->id() === null || $evaluacion->estado() !== EstadoPlan::VENCIDO) {
            throw new DomainException('Solo un plan persistido y vencido puede materializar un aviso.');
        }

        return new self(
            null,
            $plan->empresaId(),
            $plan->id(),
            $plan->equipoId(),
            self::claveCicloPara($plan),
            $evaluacion->criteriosDisparadores(),
            $fechaDeteccion,
            EstadoGestionAviso::PENDIENTE,
            null,
            null,
        );
    }

    /**
     * Identidad del ciclo al que pertenece un aviso: plan + proximo objetivo.
     *
     * Permite distinguir "el aviso del ciclo que este cierre acaba de dejar
     * atras" de "un aviso de otro ciclo" o "uno recien detectado", que no
     * deben resolverse por el mismo motivo.
     */
    public static function claveCicloPara(PlanMantenimiento $plan): string
    {
        $cyclePayload = implode('|', [
            'plan:' . $plan->id(),
            'fecha:' . ($plan->proximaFecha()?->format('Y-m-d') ?? '-'),
            'km:' . ($plan->proximoKm() ?? '-'),
            'horas_decimas:' . ($plan->proximasHorasDecimas() ?? '-'),
        ]);

        return hash('sha256', $cyclePayload);
    }

    /** @param list<string> $criteriosDisparadores */
    public static function reconstituir(
        int $id,
        int $empresaId,
        int $planId,
        int $equipoId,
        string $claveCiclo,
        array $criteriosDisparadores,
        DateTimeImmutable $fechaDeteccion,
        EstadoGestionAviso $estadoGestion,
        ?DateTimeImmutable $fechaResolucion,
        ?string $motivoResolucion,
    ): self {
        return new self(
            $id,
            $empresaId,
            $planId,
            $equipoId,
            $claveCiclo,
            $criteriosDisparadores,
            $fechaDeteccion,
            $estadoGestion,
            $fechaResolucion,
            $motivoResolucion,
        );
    }

    /**
     * CONVERTIDO es terminal: significa "este aviso ya fue atendido por una
     * orden de trabajo". El flujo que convierte el aviso escribe fecha_resolucion
     * y el motivo con el numero de OT, asi que la conversion ES la resolucion.
     *
     * RESUELTO queda para los avisos que dejan de requerir atencion SIN llegar a
     * convertirse en orden: el ciclo se supero porque se cerro una OT generada
     * desde el plan y no desde este aviso.
     */
    public function marcarConvertido(DateTimeImmutable $fecha): void
    {
        if ($this->estadoGestion !== EstadoGestionAviso::PENDIENTE) {
            throw new DomainException('Solo un aviso pendiente puede convertirse en orden.');
        }

        $this->estadoGestion   = EstadoGestionAviso::CONVERTIDO;
        $this->fechaResolucion = $fecha;
    }

    /**
     * Solo un aviso PENDIENTE puede quedar obsoleto por un cambio de ciclo.
     * Un aviso CONVERTIDO ya fue atendido y no debe reescribirse.
     */
    public function marcarResuelto(DateTimeImmutable $fecha, string $motivo): void
    {
        if ($this->estadoGestion !== EstadoGestionAviso::PENDIENTE) {
            throw new DomainException('Solo un aviso pendiente puede marcarse como resuelto.');
        }

        $motivo = trim($motivo);
        if ($motivo === '') {
            throw new DomainException('Un aviso resuelto requiere el motivo por el que dejo de requerir atencion.');
        }

        $this->estadoGestion     = EstadoGestionAviso::RESUELTO;
        $this->fechaResolucion   = $fecha;
        $this->motivoResolucion  = $motivo;
    }

    public function id(): ?int { return $this->id; }
    public function empresaId(): int { return $this->empresaId; }
    public function planId(): int { return $this->planId; }
    public function equipoId(): int { return $this->equipoId; }
    public function claveCiclo(): string { return $this->claveCiclo; }
    /** @return list<string> */
    public function criteriosDisparadores(): array { return $this->criteriosDisparadores; }
    public function fechaDeteccion(): DateTimeImmutable { return $this->fechaDeteccion; }
    public function estadoGestion(): EstadoGestionAviso { return $this->estadoGestion; }
    public function fechaResolucion(): ?DateTimeImmutable { return $this->fechaResolucion; }
    public function motivoResolucion(): ?string { return $this->motivoResolucion; }
}
