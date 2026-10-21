<?php

declare(strict_types=1);

namespace App\Domain\Trips;

use DateTimeImmutable;
use DomainException;

final class Trip
{
    public const PLANIFICADO = 'PLANIFICADO';
    public const EN_CURSO = 'EN_CURSO';
    public const FINALIZADO = 'FINALIZADO';
    public const CANCELADO = 'CANCELADO';

    private const STATES = [self::PLANIFICADO, self::EN_CURSO, self::FINALIZADO, self::CANCELADO];

    /**
     * @param list<TripAssignment> $assignments
     * @param list<TripStop> $stops
     */
    private function __construct(
        private ?int $id,
        private readonly int $companyId,
        private readonly int $branchId,
        private readonly int $number,
        private string $status,
        private readonly DateTimeImmutable $plannedDeparture,
        private readonly DateTimeImmutable $plannedReturn,
        private ?DateTimeImmutable $actualDeparture,
        private ?DateTimeImmutable $actualReturn,
        private ?int $departureKm,
        private ?int $returnKm,
        private array $assignments,
        private array $stops,
        private string $notes,
        private ?string $cancellationReason = null,
    ) {
        if ($companyId <= 0 || $branchId <= 0) {
            throw new DomainException('El viaje necesita empresa y sucursal de origen.');
        }
        if ($number <= 0) {
            throw new DomainException('El número de viaje no es válido.');
        }
        if ($plannedReturn <= $plannedDeparture) {
            throw new DomainException('El regreso planificado debe ser posterior a la salida planificada.');
        }
        if ($departureKm !== null && $departureKm < 0) {
            throw new DomainException('El odómetro de salida no puede ser negativo.');
        }
        $this->assertState($status);
        $this->assertAssignments($assignments);
        $this->assertStops($stops);
    }

    /**
     * @param list<TripAssignment> $assignments
     * @param list<TripStop> $stops
     */
    public static function plan(
        int $companyId,
        int $branchId,
        int $number,
        DateTimeImmutable $plannedDeparture,
        DateTimeImmutable $plannedReturn,
        array $assignments,
        array $stops,
        string $notes = '',
        ?int $departureKm = null,
    ): self {
        return new self(
            null,
            $companyId,
            $branchId,
            $number,
            self::PLANIFICADO,
            $plannedDeparture,
            $plannedReturn,
            null,
            null,
            $departureKm,
            null,
            array_values($assignments),
            array_values($stops),
            trim($notes),
        );
    }

    /**
     * @param list<TripAssignment> $assignments
     * @param list<TripStop> $stops
     */
    public static function reconstitute(
        int $id,
        int $companyId,
        int $branchId,
        int $number,
        string $status,
        DateTimeImmutable $plannedDeparture,
        DateTimeImmutable $plannedReturn,
        ?DateTimeImmutable $actualDeparture,
        ?DateTimeImmutable $actualReturn,
        ?int $departureKm,
        ?int $returnKm,
        array $assignments,
        array $stops,
        string $notes,
        ?string $cancellationReason = null,
    ): self {
        return new self(
            $id,
            $companyId,
            $branchId,
            $number,
            $status,
            $plannedDeparture,
            $plannedReturn,
            $actualDeparture,
            $actualReturn,
            $departureKm,
            $returnKm,
            array_values($assignments),
            array_values($stops),
            trim($notes),
            $cancellationReason,
        );
    }

    public function start(DateTimeImmutable $at, int $departureKm): void
    {
        if ($this->status !== self::PLANIFICADO) {
            throw new DomainException('Solo se puede iniciar un viaje planificado.');
        }
        if ($departureKm < 0) {
            throw new DomainException('El odómetro de salida no puede ser negativo.');
        }

        $this->status = self::EN_CURSO;
        $this->actualDeparture = $at;
        $this->departureKm = $departureKm;
    }

    public function cancel(string $reason): void
    {
        if ($this->status !== self::PLANIFICADO) {
            throw new DomainException('Solo se puede cancelar un viaje que todavía no empezó.');
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException('Indicá el motivo de cancelación del viaje.');
        }

        $this->status = self::CANCELADO;
        $this->cancellationReason = $reason;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function companyId(): int
    {
        return $this->companyId;
    }

    public function branchId(): int
    {
        return $this->branchId;
    }

    public function number(): int
    {
        return $this->number;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function isPlanned(): bool
    {
        return $this->status === self::PLANIFICADO;
    }

    public function plannedDeparture(): DateTimeImmutable
    {
        return $this->plannedDeparture;
    }

    public function plannedReturn(): DateTimeImmutable
    {
        return $this->plannedReturn;
    }

    public function actualDeparture(): ?DateTimeImmutable
    {
        return $this->actualDeparture;
    }

    public function actualReturn(): ?DateTimeImmutable
    {
        return $this->actualReturn;
    }

    public function departureKm(): ?int
    {
        return $this->departureKm;
    }

    public function returnKm(): ?int
    {
        return $this->returnKm;
    }

    /** @return list<TripAssignment> */
    public function assignments(): array
    {
        return $this->assignments;
    }

    /** @return list<TripStop> */
    public function stops(): array
    {
        return $this->stops;
    }

    public function notes(): string
    {
        return $this->notes;
    }

    public function cancellationReason(): ?string
    {
        return $this->cancellationReason;
    }

    public function truckId(): int
    {
        return $this->assignmentId(TripAssignment::TRACTOR);
    }

    public function trailerId(): ?int
    {
        foreach ($this->assignments as $assignment) {
            if ($assignment->role() === TripAssignment::ACOPLADO) {
                return $assignment->referenceId();
            }
        }

        return null;
    }

    /** @return list<int> */
    public function driverIds(): array
    {
        $ids = [];
        foreach ($this->assignments as $assignment) {
            if ($assignment->role() === TripAssignment::CHOFER) {
                $ids[] = $assignment->referenceId();
            }
        }

        return $ids;
    }

    /** @param list<TripAssignment> $assignments */
    private function assertAssignments(array $assignments): void
    {
        $tractors = 0;
        $trailers = 0;
        $drivers = 0;
        $equipmentIds = [];
        $driverIds = [];

        foreach ($assignments as $assignment) {
            if (! $assignment instanceof TripAssignment) {
                throw new DomainException('La asignación del viaje no es válida.');
            }
            if ($assignment->role() === TripAssignment::TRACTOR) {
                $tractors++;
            } elseif ($assignment->role() === TripAssignment::ACOPLADO) {
                $trailers++;
            } else {
                if (isset($driverIds[$assignment->referenceId()])) {
                    throw new DomainException('Un chofer no puede estar asignado dos veces al mismo viaje.');
                }
                $driverIds[$assignment->referenceId()] = true;
                $drivers++;
            }
            if ($assignment->isEquipment()) {
                if (isset($equipmentIds[$assignment->referenceId()])) {
                    throw new DomainException('Un equipo no puede ocupar dos roles en el mismo viaje.');
                }
                $equipmentIds[$assignment->referenceId()] = true;
            }
        }

        if ($tractors !== 1) {
            throw new DomainException('El viaje necesita exactamente un tractor o camión.');
        }
        if ($trailers > 1) {
            throw new DomainException('El viaje admite como máximo un acoplado.');
        }
        if ($drivers < 1) {
            throw new DomainException('El viaje necesita al menos un chofer.');
        }
    }

    /** @param list<TripStop> $stops */
    private function assertStops(array $stops): void
    {
        if (count($stops) < 2) {
            throw new DomainException('El recorrido necesita al menos una salida y una llegada.');
        }
        foreach ($stops as $index => $stop) {
            if (! $stop instanceof TripStop) {
                throw new DomainException('La parada del viaje no es válida.');
            }
            if ($stop->order() !== $index + 1) {
                throw new DomainException('Las paradas deben estar ordenadas de forma consecutiva desde 1.');
            }
        }
    }

    private function assertState(string $status): void
    {
        if (! in_array($status, self::STATES, true)) {
            throw new DomainException('El estado del viaje no es válido.');
        }
    }

    private function assignmentId(string $role): int
    {
        foreach ($this->assignments as $assignment) {
            if ($assignment->role() === $role) {
                return $assignment->referenceId();
            }
        }

        throw new DomainException('El viaje no tiene la asignación requerida.');
    }
}
