<?php

declare(strict_types=1);

namespace App\Domain\PreventiveMaintenance;

use DateTimeImmutable;
use DomainException;
use InvalidArgumentException;

final class PlanMantenimiento
{
    private function __construct(
        private ?int $id,
        private readonly int $empresaId,
        private readonly int $equipoId,
        private readonly int $tipoServicioId,
        private readonly ?int $intervaloKm,
        private readonly ?int $intervaloHorasDecimas,
        private readonly ?int $intervaloDias,
        private readonly ?int $anticipacionKm,
        private readonly ?int $anticipacionHorasDecimas,
        private readonly ?int $anticipacionDias,
        private ?int $baseKm,
        private ?int $baseHorasDecimas,
        private ?DateTimeImmutable $baseFecha,
        private ?int $proximoKm,
        private ?int $proximasHorasDecimas,
        private ?DateTimeImmutable $proximaFecha,
        private readonly string $prioridad,
        private bool $activo,
        private readonly ?string $observaciones,
        private readonly ?int $origenPlantillaId,
        private readonly ?int $origenPlantillaItemId,
    ) {
        if ($empresaId <= 0 || $equipoId <= 0 || $tipoServicioId <= 0) {
            throw new InvalidArgumentException('Empresa, equipo y tipo de servicio deben ser validos.');
        }

        $this->validateConfiguration();
        $this->validateTargets();

        if (($this->origenPlantillaId === null) !== ($this->origenPlantillaItemId === null)
            || ($this->origenPlantillaId !== null && ($this->origenPlantillaId <= 0 || $this->origenPlantillaItemId <= 0))) {
            throw new InvalidArgumentException('El origen de plantilla del plan no es valido.');
        }
    }

public static function asignar(
        int $empresaId,
        int $equipoId,
        int $tipoServicioId,
        ?int $intervaloKm,
        ?int $intervaloHorasDecimas,
        ?int $intervaloDias,
        ?int $anticipacionKm,
        ?int $anticipacionHorasDecimas,
        ?int $anticipacionDias,
        ?int $baseKm,
        ?int $baseHorasDecimas,
        ?DateTimeImmutable $baseFecha,
        string $prioridad = 'MEDIA',
        ?string $observaciones = null,
        ?int $origenPlantillaId = null,
        ?int $origenPlantillaItemId = null,
    ): self {
        return self::nuevo(
            null,
            $empresaId,
            $equipoId,
            $tipoServicioId,
            $intervaloKm,
            $intervaloHorasDecimas,
            $intervaloDias,
            $anticipacionKm,
            $anticipacionHorasDecimas,
            $anticipacionDias,
            $baseKm,
            $baseHorasDecimas,
            $baseFecha,
            $prioridad,
            true,
            $observaciones,
            $origenPlantillaId,
            $origenPlantillaItemId,
        );
    }

    /**
     * Reconfigura un plan existente conservando su identidad (id, empresa, equipo,
     * tipo de servicio y origen de plantilla) y recalcula los proximos objetivos
     * a partir de las bases informadas.
     */
    public static function reconfigurar(
        int $id,
        int $empresaId,
        int $equipoId,
        int $tipoServicioId,
        ?int $intervaloKm,
        ?int $intervaloHorasDecimas,
        ?int $intervaloDias,
        ?int $anticipacionKm,
        ?int $anticipacionHorasDecimas,
        ?int $anticipacionDias,
        ?int $baseKm,
        ?int $baseHorasDecimas,
        ?DateTimeImmutable $baseFecha,
        string $prioridad,
        ?string $observaciones,
        ?int $origenPlantillaId = null,
        ?int $origenPlantillaItemId = null,
    ): self {
        if ($id <= 0) {
            throw new InvalidArgumentException('El identificador del plan debe ser valido.');
        }

        return self::nuevo(
            $id,
            $empresaId,
            $equipoId,
            $tipoServicioId,
            $intervaloKm,
            $intervaloHorasDecimas,
            $intervaloDias,
            $anticipacionKm,
            $anticipacionHorasDecimas,
            $anticipacionDias,
            $baseKm,
            $baseHorasDecimas,
            $baseFecha,
            $prioridad,
            true,
            $observaciones,
            $origenPlantillaId,
            $origenPlantillaItemId,
        );
    }

    private static function nuevo(
        ?int $id,
        int $empresaId,
        int $equipoId,
        int $tipoServicioId,
        ?int $intervaloKm,
        ?int $intervaloHorasDecimas,
        ?int $intervaloDias,
        ?int $anticipacionKm,
        ?int $anticipacionHorasDecimas,
        ?int $anticipacionDias,
        ?int $baseKm,
        ?int $baseHorasDecimas,
        ?DateTimeImmutable $baseFecha,
        string $prioridad,
        bool $activo,
        ?string $observaciones,
        ?int $origenPlantillaId,
        ?int $origenPlantillaItemId,
    ): self {
        $proximoKm            = $intervaloKm === null || $baseKm === null ? null : $baseKm + $intervaloKm;
        $proximasHorasDecimas = $intervaloHorasDecimas === null || $baseHorasDecimas === null
            ? null
            : $baseHorasDecimas + $intervaloHorasDecimas;
        $proximaFecha = $intervaloDias === null || $baseFecha === null
            ? null
            : self::dateOnly($baseFecha)->modify('+' . $intervaloDias . ' days');

        return new self(
            $id,
            $empresaId,
            $equipoId,
            $tipoServicioId,
            $intervaloKm,
            $intervaloHorasDecimas,
            $intervaloDias,
            $anticipacionKm,
            $anticipacionHorasDecimas,
            $anticipacionDias,
            $baseKm,
            $baseHorasDecimas,
            $baseFecha === null ? null : self::dateOnly($baseFecha),
            $proximoKm,
            $proximasHorasDecimas,
            $proximaFecha,
            $prioridad,
            $activo,
            $observaciones,
            $origenPlantillaId,
            $origenPlantillaItemId,
        );
    }

    public static function reconstituir(
        int $id,
        int $empresaId,
        int $equipoId,
        int $tipoServicioId,
        ?int $intervaloKm,
        ?int $intervaloHorasDecimas,
        ?int $intervaloDias,
        ?int $anticipacionKm,
        ?int $anticipacionHorasDecimas,
        ?int $anticipacionDias,
        ?int $baseKm,
        ?int $baseHorasDecimas,
        ?DateTimeImmutable $baseFecha,
        ?int $proximoKm,
        ?int $proximasHorasDecimas,
        ?DateTimeImmutable $proximaFecha,
        string $prioridad,
        bool $activo,
        ?string $observaciones,
        ?int $origenPlantillaId = null,
        ?int $origenPlantillaItemId = null,
    ): self {
        if ($id <= 0) {
            throw new InvalidArgumentException('El identificador del plan debe ser valido.');
        }

        return new self(
            $id,
            $empresaId,
            $equipoId,
            $tipoServicioId,
            $intervaloKm,
            $intervaloHorasDecimas,
            $intervaloDias,
            $anticipacionKm,
            $anticipacionHorasDecimas,
            $anticipacionDias,
            $baseKm,
            $baseHorasDecimas,
            $baseFecha === null ? null : self::dateOnly($baseFecha),
            $proximoKm,
            $proximasHorasDecimas,
            $proximaFecha === null ? null : self::dateOnly($proximaFecha),
            $prioridad,
            $activo,
            $observaciones,
            $origenPlantillaId,
            $origenPlantillaItemId,
        );
    }

    public function recalcularDesdeCierre(
        DateTimeImmutable $fechaFinalizacion,
        ?int $kmSalida,
        ?int $horasSalidaDecimas,
    ): void {
        if (! $this->activo) {
            throw new DomainException('No se puede recalcular un plan inactivo.');
        }

        if ($this->usaKilometraje() && $kmSalida === null) {
            throw new DomainException('El cierre requiere kilometraje de salida para este plan.');
        }

        if ($this->usaHorometro() && $horasSalidaDecimas === null) {
            throw new DomainException('El cierre requiere horometro de salida para este plan.');
        }

        if (($kmSalida !== null && $kmSalida < 0) || ($horasSalidaDecimas !== null && $horasSalidaDecimas < 0)) {
            throw new DomainException('Las lecturas de salida no pueden ser negativas.');
        }

        if ($this->usaKilometraje()) {
            $this->baseKm    = $kmSalida;
            $this->proximoKm = $kmSalida + $this->intervaloKm;
        }

        if ($this->usaHorometro()) {
            $this->baseHorasDecimas     = $horasSalidaDecimas;
            $this->proximasHorasDecimas = $horasSalidaDecimas + $this->intervaloHorasDecimas;
        }

        if ($this->usaFecha()) {
            $this->baseFecha    = self::dateOnly($fechaFinalizacion);
            $this->proximaFecha = $this->baseFecha->modify('+' . $this->intervaloDias . ' days');
        }
    }

    public function desactivar(): void
    {
        $this->activo = false;
    }

    private function validateConfiguration(): void
    {
        if ($this->intervaloKm === null && $this->intervaloHorasDecimas === null && $this->intervaloDias === null) {
            throw new InvalidArgumentException('El plan debe configurar al menos un criterio.');
        }

        $this->validateCriterion($this->intervaloKm, $this->anticipacionKm, 'kilometraje');
        $this->validateCriterion($this->intervaloHorasDecimas, $this->anticipacionHorasDecimas, 'horometro');
        $this->validateCriterion($this->intervaloDias, $this->anticipacionDias, 'fecha');

        if (! in_array($this->prioridad, ['BAJA', 'MEDIA', 'ALTA', 'CRITICA'], true)) {
            throw new InvalidArgumentException('La prioridad del plan no es valida.');
        }
    }

    private function validateCriterion(?int $intervalo, ?int $anticipacion, string $nombre): void
    {
        if ($intervalo === null) {
            if ($anticipacion !== null) {
                throw new InvalidArgumentException("La anticipacion de {$nombre} requiere un intervalo.");
            }

            return;
        }

        if ($intervalo <= 0) {
            throw new InvalidArgumentException("El intervalo de {$nombre} debe ser positivo.");
        }

        if ($anticipacion === null || $anticipacion < 0 || $anticipacion >= $intervalo) {
            throw new InvalidArgumentException("La anticipacion de {$nombre} debe ser no negativa y menor al intervalo.");
        }
    }

    private function validateTargets(): void
    {
        $this->validateTarget($this->intervaloKm, $this->baseKm, $this->proximoKm, 'kilometraje');
        $this->validateTarget(
            $this->intervaloHorasDecimas,
            $this->baseHorasDecimas,
            $this->proximasHorasDecimas,
            'horometro',
        );

        if ($this->intervaloDias === null) {
            if ($this->baseFecha !== null || $this->proximaFecha !== null) {
                throw new InvalidArgumentException('Un plan sin intervalo de fecha no puede tener fechas base u objetivo.');
            }
            return;
        }

        if ($this->baseFecha === null && $this->proximaFecha === null) {
            return;
        }

        if ($this->baseFecha === null || $this->proximaFecha === null
            || $this->proximaFecha != $this->baseFecha->modify('+' . $this->intervaloDias . ' days')) {
            throw new InvalidArgumentException('La base y el proximo objetivo por fecha no son coherentes.');
        }
    }

    private function validateTarget(?int $intervalo, ?int $base, ?int $proximo, string $nombre): void
    {
        if ($intervalo === null) {
            if ($base !== null || $proximo !== null) {
                throw new InvalidArgumentException("Un plan sin intervalo de {$nombre} no puede tener base u objetivo.");
            }
            return;
        }

        if ($base === null && $proximo === null) {
            return;
        }

        if ($base === null || $base < 0 || $proximo !== $base + $intervalo) {
            throw new InvalidArgumentException("La base y el proximo objetivo de {$nombre} no son coherentes.");
        }
    }

    private static function dateOnly(DateTimeImmutable $date): DateTimeImmutable
    {
        return $date->setTime(0, 0, 0);
    }

    public function id(): ?int { return $this->id; }
    public function empresaId(): int { return $this->empresaId; }
    public function equipoId(): int { return $this->equipoId; }
    public function tipoServicioId(): int { return $this->tipoServicioId; }
    public function intervaloKm(): ?int { return $this->intervaloKm; }
    public function intervaloHorasDecimas(): ?int { return $this->intervaloHorasDecimas; }
    public function intervaloDias(): ?int { return $this->intervaloDias; }
    public function anticipacionKm(): ?int { return $this->anticipacionKm; }
    public function anticipacionHorasDecimas(): ?int { return $this->anticipacionHorasDecimas; }
    public function anticipacionDias(): ?int { return $this->anticipacionDias; }
    public function baseKm(): ?int { return $this->baseKm; }
    public function baseHorasDecimas(): ?int { return $this->baseHorasDecimas; }
    public function baseFecha(): ?DateTimeImmutable { return $this->baseFecha; }
    public function proximoKm(): ?int { return $this->proximoKm; }
    public function proximasHorasDecimas(): ?int { return $this->proximasHorasDecimas; }
    public function proximaFecha(): ?DateTimeImmutable { return $this->proximaFecha; }
    public function prioridad(): string { return $this->prioridad; }
    public function activo(): bool { return $this->activo; }
    public function observaciones(): ?string { return $this->observaciones; }
    public function origenPlantillaId(): ?int { return $this->origenPlantillaId; }
    public function origenPlantillaItemId(): ?int { return $this->origenPlantillaItemId; }
    public function usaKilometraje(): bool { return $this->intervaloKm !== null; }
    public function usaHorometro(): bool { return $this->intervaloHorasDecimas !== null; }
    public function usaFecha(): bool { return $this->intervaloDias !== null; }
}
