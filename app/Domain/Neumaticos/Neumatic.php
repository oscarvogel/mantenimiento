<?php

declare(strict_types=1);

namespace App\Domain\Neumaticos;

use DateTimeImmutable;
use DomainException;

/**
 * Agregado raíz del contexto Neumáticos.
 *
 * El neumático es un activo con historial propio, independiente del equipo que
 * lo monta: se identifica por empresa y número, recorre su ciclo de vida y
 * acumula colocaciones. Equipo, marca, modelo y tipo de cubierta se
 * referencian por ID. Un neumático no se borra: se da de baja.
 *
 * Invariantes que custodia (plan de ampliación §5.2):
 * 1. El número no cambia una vez dado de alta.
 * 2. Hay a lo sumo una colocación activa.
 * 3. La extracción no es anterior a la colocación.
 * 4. Un kilometraje de extracción menor al de colocación deja el rendimiento
 *    como no confiable, sin inventar un número.
 * 6. Las transiciones de estado las decide `NeumaticStatus`.
 */
final class Neumatic
{
    /**
     * @param list<NeumaticMounting> $mountings
     * @param list<NeumaticStatusChange> $statusLog
     */
    private function __construct(
        private readonly ?int $id,
        private readonly int $companyId,
        private int $branchId,
        private readonly NeumaticNumber $number,
        private ?int $brandId,
        private ?int $modelId,
        private ?int $coverTypeId,
        private string $size,
        private ?int $manufactureYear,
        private NeumaticStatus $status,
        private array $mountings,
        private array $statusLog,
    ) {
        if ($companyId <= 0 || $branchId <= 0) {
            throw new DomainException('El neumático necesita empresa y sucursal.');
        }
        $this->assertMountings($mountings);
    }

    public static function register(
        int $companyId,
        int $branchId,
        NeumaticNumber $number,
        DateTimeImmutable $registeredAt,
        ?int $brandId = null,
        ?int $modelId = null,
        ?int $coverTypeId = null,
        string $size = '',
        ?int $manufactureYear = null,
        NeumaticStatus $initialStatus = NeumaticStatus::NEW,
    ): self {
        if ($initialStatus->isFinal()) {
            throw new DomainException('Un neumático no se da de alta en un estado final.');
        }
        self::assertManufactureYear($manufactureYear, $registeredAt);

        return new self(
            null,
            $companyId,
            $branchId,
            $number,
            self::optionalId($brandId),
            self::optionalId($modelId),
            self::optionalId($coverTypeId),
            trim($size),
            $manufactureYear,
            $initialStatus,
            [],
            [new NeumaticStatusChange(null, $initialStatus, $registeredAt, 'Alta del neumático')],
        );
    }

    /**
     * @param list<NeumaticMounting> $mountings ordenadas por fecha de colocación
     * @param list<NeumaticStatusChange> $statusLog
     */
    public static function reconstitute(
        int $id,
        int $companyId,
        int $branchId,
        NeumaticNumber $number,
        ?int $brandId,
        ?int $modelId,
        ?int $coverTypeId,
        string $size,
        ?int $manufactureYear,
        NeumaticStatus $status,
        array $mountings,
        array $statusLog,
    ): self {
        return new self(
            $id,
            $companyId,
            $branchId,
            $number,
            $brandId,
            $modelId,
            $coverTypeId,
            $size,
            $manufactureYear,
            $status,
            array_values($mountings),
            array_values($statusLog),
        );
    }

    /**
     * Corrige los datos descriptivos. El número no está entre ellos: es la
     * identidad del neumático.
     */
    public function describe(?int $brandId, ?int $modelId, ?int $coverTypeId, string $size, ?int $manufactureYear, DateTimeImmutable $at): void
    {
        self::assertManufactureYear($manufactureYear, $at);
        $this->brandId = self::optionalId($brandId);
        $this->modelId = self::optionalId($modelId);
        $this->coverTypeId = self::optionalId($coverTypeId);
        $this->size = trim($size);
        $this->manufactureYear = $manufactureYear;
    }

    public function changeStatus(NeumaticStatus $target, DateTimeImmutable $at, string $reason): void
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException('Indicá el motivo del cambio de estado.');
        }
        if (! $this->status->canTransitionTo($target)) {
            throw new DomainException(sprintf(
                'Un neumático en estado "%s" no puede pasar a "%s".',
                $this->status->label(),
                $target->label(),
            ));
        }
        if ($this->isMounted() && ! $target->canBeMounted()) {
            throw new DomainException(sprintf(
                'Extraé el neumático del equipo antes de pasarlo a "%s".',
                $target->label(),
            ));
        }
        $last = $this->statusLog === [] ? null : $this->statusLog[array_key_last($this->statusLog)];
        if ($last !== null && $at < $last->at) {
            throw new DomainException('El cambio de estado no puede ser anterior al último registrado.');
        }

        $this->statusLog[] = new NeumaticStatusChange($this->status, $target, $at, $reason);
        $this->status = $target;
    }

    public function mount(int $equipmentId, NeumaticPosition $position, DateTimeImmutable $at, int $kilometers): void
    {
        if ($this->isMounted()) {
            throw new DomainException('El neumático ya está colocado en un equipo. Extraelo antes de volver a colocarlo.');
        }
        if (! $this->status->canBeMounted()) {
            throw new DomainException(sprintf(
                'Un neumático en estado "%s" no se puede colocar en un equipo.',
                $this->status->label(),
            ));
        }
        $last = $this->lastMounting();
        if ($last !== null && $last->removedAt !== null && $at < $last->removedAt) {
            throw new DomainException('La colocación no puede ser anterior a la última extracción del neumático.');
        }

        $this->mountings[] = new NeumaticMounting($equipmentId, $position, $at, $kilometers);
    }

    /**
     * Registra la extracción y devuelve el rendimiento de esa colocación.
     */
    public function remove(DateTimeImmutable $at, int $kilometers): NeumaticPerformance
    {
        $key = $this->activeMountingKey();
        if ($key === null) {
            throw new DomainException('El neumático no está colocado en ningún equipo.');
        }

        $removed = $this->mountings[$key]->removedOn($at, $kilometers);
        $this->mountings[$key] = $removed;

        return $removed->performance() ?? throw new DomainException('La extracción no dejó rendimiento.');
    }

    /**
     * Pasa el neumático a otra sucursal de la misma empresa. Solo se mueve uno
     * que no esté montado: el montado acompaña al equipo.
     */
    public function moveToBranch(int $branchId): void
    {
        if ($branchId <= 0) {
            throw new DomainException('Indicá la sucursal de destino.');
        }
        if ($this->isMounted()) {
            throw new DomainException('Extraé el neumático del equipo antes de cambiarlo de sucursal.');
        }
        $this->branchId = $branchId;
    }

    public function isMounted(): bool
    {
        return $this->activeMountingKey() !== null;
    }

    public function currentMounting(): ?NeumaticMounting
    {
        $key = $this->activeMountingKey();

        return $key === null ? null : $this->mountings[$key];
    }

    /**
     * Suma de los kilómetros de las colocaciones con rendimiento confiable.
     * Null si ninguna colocación cerrada tiene datos válidos.
     */
    public function totalKilometers(): ?int
    {
        $total = null;
        foreach ($this->mountings as $mounting) {
            $performance = $mounting->performance();
            if ($performance !== null && $performance->reliable) {
                $total = ($total ?? 0) + (int) $performance->kilometers;
            }
        }

        return $total;
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

    public function number(): NeumaticNumber
    {
        return $this->number;
    }

    public function brandId(): ?int
    {
        return $this->brandId;
    }

    public function modelId(): ?int
    {
        return $this->modelId;
    }

    public function coverTypeId(): ?int
    {
        return $this->coverTypeId;
    }

    public function size(): string
    {
        return $this->size;
    }

    public function manufactureYear(): ?int
    {
        return $this->manufactureYear;
    }

    public function status(): NeumaticStatus
    {
        return $this->status;
    }

    /**
     * @return list<NeumaticMounting>
     */
    public function mountings(): array
    {
        return $this->mountings;
    }

    /**
     * @return list<NeumaticStatusChange>
     */
    public function statusLog(): array
    {
        return $this->statusLog;
    }

    private function lastMounting(): ?NeumaticMounting
    {
        return $this->mountings === [] ? null : $this->mountings[array_key_last($this->mountings)];
    }

    private function activeMountingKey(): ?int
    {
        foreach ($this->mountings as $key => $mounting) {
            if ($mounting->isActive()) {
                return $key;
            }
        }

        return null;
    }

    /**
     * @param list<NeumaticMounting> $mountings
     */
    private function assertMountings(array $mountings): void
    {
        $active = 0;
        foreach ($mountings as $mounting) {
            if (! $mounting instanceof NeumaticMounting) {
                throw new DomainException('La colocación del neumático no es válida.');
            }
            if ($mounting->isActive()) {
                $active++;
            }
        }
        if ($active > 1) {
            throw new DomainException('Un neumático no puede estar colocado en dos lugares a la vez.');
        }
    }

    private static function assertManufactureYear(?int $year, DateTimeImmutable $reference): void
    {
        if ($year === null) {
            return;
        }
        if ($year < 1900 || $year > (int) $reference->format('Y')) {
            throw new DomainException('El año de fabricación no es válido.');
        }
    }

    private static function optionalId(?int $id): ?int
    {
        if ($id !== null && $id <= 0) {
            throw new DomainException('La referencia del catálogo no es válida.');
        }

        return $id;
    }
}
