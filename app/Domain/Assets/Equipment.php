<?php

declare(strict_types=1);

namespace App\Domain\Assets;

use App\Domain\Measurement\UsageMeasurement;
use DateTimeImmutable;
use DomainException;

final class Equipment
{
    public const ACTIVE = 'ACTIVO';
    public const INACTIVE = 'BAJA';

    private function __construct(
        private readonly ?int $id,
        private readonly int $companyId,
        private int $branchId,
        private EquipmentType $type,
        private string $code,
        private ?string $plate,
        private string $status,
        private DateTimeImmutable $registeredAt,
        private ?DateTimeImmutable $decommissionedAt,
        private ?string $notes,
        private ?int $currentKilometers,
        private ?int $currentHoursTenths,
        private ?int $brandId = null,
        private ?int $modelId = null,
        private ?int $year = null,
        private ?string $chassis = null,
        private ?string $engine = null,
    ) {
        if ($companyId <= 0 || $branchId <= 0) {
            throw new DomainException('La empresa y la sucursal del equipo deben ser válidas.');
        }
        if ($code === '' || mb_strlen($code) > 50) {
            throw new DomainException('El código del equipo es obligatorio y admite hasta 50 caracteres.');
        }
        if ($plate !== null && mb_strlen($plate) > 20) {
            throw new DomainException('La patente admite hasta 20 caracteres.');
        }
        if (! in_array($status, [self::ACTIVE, self::INACTIVE], true)) {
            throw new DomainException('El estado del equipo no es válido.');
        }
        if ($currentKilometers !== null && $currentKilometers < 0) {
            throw new DomainException('El kilometraje actual no puede ser negativo.');
        }
        if ($status === self::INACTIVE && $decommissionedAt === null) {
            throw new DomainException('Un equipo dado de baja debe conservar su fecha de baja.');
        }
        $this->setTechnicalProfile($brandId, $modelId, $year, $chassis, $engine);
    }

    public static function create(
        int $companyId,
        int $branchId,
        EquipmentType $type,
        string $code,
        ?string $plate,
        DateTimeImmutable $registeredAt,
        ?string $notes = null,
        ?int $brandId = null,
        ?int $modelId = null,
        ?int $year = null,
        ?string $chassis = null,
        ?string $engine = null,
    ): self {
        return new self(
            null,
            $companyId,
            $branchId,
            $type,
            self::normalizeCode($code),
            self::normalizeNullableCode($plate),
            self::ACTIVE,
            $registeredAt,
            null,
            self::normalizeNullableText($notes),
            null,
            null,
            $brandId,
            $modelId,
            $year,
            $chassis,
            $engine,
        );
    }

    public static function reconstitute(
        int $id,
        int $companyId,
        int $branchId,
        EquipmentType $type,
        string $code,
        ?string $plate,
        string $status,
        DateTimeImmutable $registeredAt,
        ?DateTimeImmutable $decommissionedAt,
        ?string $notes,
        ?int $currentKilometers,
        int|float|string|null $currentHours,
        ?int $brandId = null,
        ?int $modelId = null,
        ?int $year = null,
        ?string $chassis = null,
        ?string $engine = null,
    ): self {
        if ($id <= 0) {
            throw new DomainException('La identidad del equipo no es válida.');
        }

        return new self(
            $id,
            $companyId,
            $branchId,
            $type,
            self::normalizeCode($code),
            self::normalizeNullableCode($plate),
            $status,
            $registeredAt,
            $decommissionedAt,
            self::normalizeNullableText($notes),
            $currentKilometers,
            UsageMeasurement::parseHours($currentHours),
            $brandId,
            $modelId,
            $year,
            $chassis,
            $engine,
        );
    }

    /**
     * Applies a valid reading to the current snapshot.
     *
     * @return bool true when at least one value is a permitted correction
     */
    public function recordUsage(
        UsageMeasurement $measurement,
        bool $canCorrect,
        ?string $correctionReason,
    ): bool {
        if ($this->status !== self::ACTIVE) {
            throw new DomainException('No se pueden registrar lecturas en un equipo dado de baja.');
        }
        if ($measurement->hasKilometers() && ! $this->type->tracksKilometers()) {
            throw new DomainException('El tipo de equipo no controla kilometraje.');
        }
        if ($measurement->hasHours() && ! $this->type->tracksHours()) {
            throw new DomainException('El tipo de equipo no controla horómetro.');
        }

        $kilometersRegress = $measurement->kilometers() !== null
            && $this->currentKilometers !== null
            && $measurement->kilometers() < $this->currentKilometers;
        $hoursRegress = $measurement->hoursTenths() !== null
            && $this->currentHoursTenths !== null
            && $measurement->hoursTenths() < $this->currentHoursTenths;
        $isCorrection = $kilometersRegress || $hoursRegress;

        if ($isCorrection) {
            $reason = trim((string) $correctionReason);
            if (! $canCorrect || mb_strlen($reason) < 5 || mb_strlen($reason) > 255) {
                throw new DomainException('Un retroceso requiere permiso y un motivo de entre 5 y 255 caracteres.');
            }
        }

        if ($measurement->kilometers() !== null) {
            $this->currentKilometers = $measurement->kilometers();
        }
        if ($measurement->hoursTenths() !== null) {
            $this->currentHoursTenths = $measurement->hoursTenths();
        }

        return $isCorrection;
    }

    public function decommission(DateTimeImmutable $date): void
    {
        if ($this->status !== self::ACTIVE) {
            throw new DomainException('El equipo ya se encuentra dado de baja.');
        }
        if ($date < $this->registeredAt) {
            throw new DomainException('La fecha de baja no puede ser anterior al alta.');
        }
        $this->status = self::INACTIVE;
        $this->decommissionedAt = $date;
    }

    public function updateProfile(
        string $code,
        ?string $plate,
        ?string $notes,
        ?int $brandId = null,
        ?int $modelId = null,
        ?int $year = null,
        ?string $chassis = null,
        ?string $engine = null,
        ?EquipmentType $type = null,
        ?DateTimeImmutable $registeredAt = null,
        ?DateTimeImmutable $today = null,
    ): void
    {
        if ($this->status !== self::ACTIVE) {
            throw new DomainException('No se puede editar un equipo dado de baja.');
        }

        $normalizedCode = self::normalizeCode($code);
        if ($normalizedCode === '' || mb_strlen($normalizedCode) > 50) {
            throw new DomainException('El código del equipo es obligatorio y admite hasta 50 caracteres.');
        }

        $normalizedPlate = self::normalizeNullableCode($plate);
        if ($normalizedPlate !== null && mb_strlen($normalizedPlate) > 20) {
            throw new DomainException('La patente admite hasta 20 caracteres.');
        }

        $this->code = $normalizedCode;
        $this->plate = $normalizedPlate;
        $this->notes = self::normalizeNullableText($notes);
        if ($type !== null) {
            if (! $type->tracksKilometers() && $this->currentKilometers !== null) {
                throw new DomainException('No se puede usar un tipo que no controle kilometraje porque el equipo ya tiene kilometraje registrado.');
            }
            if (! $type->tracksHours() && $this->currentHoursTenths !== null) {
                throw new DomainException('No se puede usar un tipo que no controle horómetro porque el equipo ya tiene horómetro registrado.');
            }
            $this->type = $type;
        }
        if ($registeredAt !== null) {
            if ($today === null || $registeredAt > $today) {
                throw new DomainException('La fecha de alta no puede ser futura.');
            }
            $this->registeredAt = $registeredAt;
        }
        $this->setTechnicalProfile($brandId, $modelId, $year, $chassis, $engine);
    }

    public function transferTo(int $destinationBranchId, DateTimeImmutable $occurredAt): int
    {
        if ($this->status !== self::ACTIVE) {
            throw new DomainException('No se puede trasladar un equipo dado de baja.');
        }
        if ($destinationBranchId <= 0) {
            throw new DomainException('La sucursal de destino debe ser válida.');
        }
        if ($destinationBranchId === $this->branchId) {
            throw new DomainException('La sucursal de destino debe ser diferente de la actual.');
        }
        if ($occurredAt < $this->registeredAt) {
            throw new DomainException('La fecha del traslado no puede ser anterior al alta.');
        }

        $originBranchId = $this->branchId;
        $this->branchId = $destinationBranchId;

        return $originBranchId;
    }

    public function id(): ?int { return $this->id; }
    public function companyId(): int { return $this->companyId; }
    public function branchId(): int { return $this->branchId; }
    public function type(): EquipmentType { return $this->type; }
    public function code(): string { return $this->code; }
    public function plate(): ?string { return $this->plate; }
    public function status(): string { return $this->status; }
    public function registeredAt(): DateTimeImmutable { return $this->registeredAt; }
    public function decommissionedAt(): ?DateTimeImmutable { return $this->decommissionedAt; }
    public function notes(): ?string { return $this->notes; }
    public function currentKilometers(): ?int { return $this->currentKilometers; }
    public function currentHoursTenths(): ?int { return $this->currentHoursTenths; }
    public function brandId(): ?int { return $this->brandId; }
    public function modelId(): ?int { return $this->modelId; }
    public function year(): ?int { return $this->year; }
    public function chassis(): ?string { return $this->chassis; }
    public function engine(): ?string { return $this->engine; }

    public function currentHours(): ?string
    {
        if ($this->currentHoursTenths === null) {
            return null;
        }

        return intdiv($this->currentHoursTenths, 10) . '.' . ($this->currentHoursTenths % 10);
    }

    private static function normalizeCode(string $value): string
    {
        return mb_strtoupper(trim($value));
    }

    private static function normalizeNullableCode(?string $value): ?string
    {
        $value = self::normalizeCode((string) $value);

        return $value === '' ? null : $value;
    }

    private static function normalizeNullableText(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function setTechnicalProfile(
        ?int $brandId,
        ?int $modelId,
        ?int $year,
        ?string $chassis,
        ?string $engine,
    ): void {
        if ($brandId !== null && $brandId <= 0) {
            throw new DomainException('La marca del equipo debe ser válida.');
        }
        if ($modelId !== null && $modelId <= 0) {
            throw new DomainException('El modelo del equipo debe ser válido.');
        }
        if ($modelId !== null && $brandId === null) {
            throw new DomainException('Un modelo requiere informar su marca.');
        }
        if ($year !== null && ($year < 1900 || $year > 9999)) {
            throw new DomainException('El año del equipo debe tener cuatro dígitos y ser igual o posterior a 1900.');
        }

        $chassis = self::normalizeNullableCode($chassis);
        $engine = self::normalizeNullableCode($engine);
        if ($chassis !== null && mb_strlen($chassis) > 100) {
            throw new DomainException('El chasis admite hasta 100 caracteres.');
        }
        if ($engine !== null && mb_strlen($engine) > 100) {
            throw new DomainException('El motor admite hasta 100 caracteres.');
        }

        $this->brandId = $brandId;
        $this->modelId = $modelId;
        $this->year = $year;
        $this->chassis = $chassis;
        $this->engine = $engine;
    }
}
