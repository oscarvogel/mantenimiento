<?php

declare(strict_types=1);

namespace App\Domain\Measurement;

use DateTimeImmutable;
use DomainException;

final class EquipmentReading
{
    public const MANUAL = 'MANUAL';
    public const WORK_ORDER = 'ORDEN_TRABAJO';
    public const IMPORT = 'IMPORTACION';
    public const QUICK_ENTRY = 'CARGA_RAPIDA';
    public const INITIAL_ENTRY = 'ALTA_INICIAL';

    private function __construct(
        private readonly ?int $id,
        private readonly int $companyId,
        private readonly int $branchId,
        private readonly int $equipmentId,
        private readonly DateTimeImmutable $recordedAt,
        private readonly UsageMeasurement $measurement,
        private readonly string $origin,
        private readonly ?string $originReference,
        private readonly int $userId,
        private readonly ?string $correctionReason,
        private readonly ?string $notes,
        private readonly ?int $correctedReadingId,
        private bool $annulled,
        private ?DateTimeImmutable $annulledAt,
        private ?int $annulledBy,
        private ?string $annulmentReason,
    ) {
        if ($companyId <= 0 || $branchId <= 0 || $equipmentId <= 0 || $userId <= 0) {
            throw new DomainException('El alcance y autor de la lectura deben ser válidos.');
        }
        if (! in_array($origin, [self::MANUAL, self::WORK_ORDER, self::IMPORT, self::QUICK_ENTRY, self::INITIAL_ENTRY], true)) {
            throw new DomainException('El origen de la lectura no es válido.');
        }
        if ($originReference !== null && mb_strlen($originReference) > 100) {
            throw new DomainException('La referencia de origen admite hasta 100 caracteres.');
        }
        if ($correctionReason !== null && (mb_strlen($correctionReason) < 5 || mb_strlen($correctionReason) > 255)) {
            throw new DomainException('El motivo de corrección debe tener entre 5 y 255 caracteres.');
        }
    }

    public static function record(
        int $companyId,
        int $branchId,
        int $equipmentId,
        DateTimeImmutable $recordedAt,
        UsageMeasurement $measurement,
        string $origin,
        ?string $originReference,
        int $userId,
        bool $isCorrection,
        ?string $correctionReason,
        ?string $notes,
    ): self {
        $reason = self::nullable($correctionReason);
        if ($isCorrection && $reason === null) {
            throw new DomainException('Una lectura de corrección debe conservar su motivo.');
        }
        if (! $isCorrection) {
            $reason = null;
        }

        return new self(
            null,
            $companyId,
            $branchId,
            $equipmentId,
            $recordedAt,
            $measurement,
            mb_strtoupper(trim($origin)),
            self::nullable($originReference),
            $userId,
            $reason,
            self::nullable($notes),
            null,
            false,
            null,
            null,
            null,
        );
    }

    public static function reconstitute(
        int $id,
        int $companyId,
        int $branchId,
        int $equipmentId,
        DateTimeImmutable $recordedAt,
        UsageMeasurement $measurement,
        string $origin,
        ?string $originReference,
        int $userId,
        ?string $correctionReason,
        ?string $notes,
        ?int $correctedReadingId,
        bool $annulled,
        ?DateTimeImmutable $annulledAt,
        ?int $annulledBy,
        ?string $annulmentReason,
    ): self {
        if ($id <= 0) {
            throw new DomainException('La identidad de la lectura debe ser válida.');
        }
        if ($correctedReadingId !== null && $correctedReadingId <= 0) {
            throw new DomainException('La lectura original vinculada debe ser válida.');
        }
        if ($annulled && ($annulledAt === null || $annulledBy === null || self::nullable($annulmentReason) === null)) {
            throw new DomainException('Una lectura anulada debe conservar fecha, autor y motivo.');
        }

        return new self(
            $id,
            $companyId,
            $branchId,
            $equipmentId,
            $recordedAt,
            $measurement,
            mb_strtoupper(trim($origin)),
            self::nullable($originReference),
            $userId,
            self::nullable($correctionReason),
            self::nullable($notes),
            $correctedReadingId,
            $annulled,
            $annulledAt,
            $annulledBy,
            self::nullable($annulmentReason),
        );
    }

    public function correct(
        UsageMeasurement $replacement,
        int $actorUserId,
        string $reason,
        ?string $notes,
        DateTimeImmutable $correctedAt,
    ): self {
        if ($this->id === null) {
            throw new DomainException('No se puede corregir una lectura sin identidad.');
        }
        if ($this->annulled) {
            throw new DomainException('La lectura ya fue anulada y no puede corregirse nuevamente.');
        }
        if ($actorUserId <= 0) {
            throw new DomainException('El autor de la corrección debe ser válido.');
        }

        $reason = self::nullable($reason);
        if ($reason === null || mb_strlen($reason) < 5 || mb_strlen($reason) > 255) {
            throw new DomainException('El motivo de corrección debe tener entre 5 y 255 caracteres.');
        }

        $this->annulled = true;
        $this->annulledAt = $correctedAt;
        $this->annulledBy = $actorUserId;
        $this->annulmentReason = $reason;

        return new self(
            null,
            $this->companyId,
            $this->branchId,
            $this->equipmentId,
            $this->recordedAt,
            $replacement,
            self::MANUAL,
            null,
            $actorUserId,
            $reason,
            self::nullable($notes),
            $this->id,
            false,
            null,
            null,
            null,
        );
    }

    public function id(): ?int { return $this->id; }
    public function companyId(): int { return $this->companyId; }
    public function branchId(): int { return $this->branchId; }
    public function equipmentId(): int { return $this->equipmentId; }
    public function recordedAt(): DateTimeImmutable { return $this->recordedAt; }
    public function measurement(): UsageMeasurement { return $this->measurement; }
    public function origin(): string { return $this->origin; }
    public function originReference(): ?string { return $this->originReference; }
    public function userId(): int { return $this->userId; }
    public function correctionReason(): ?string { return $this->correctionReason; }
    public function notes(): ?string { return $this->notes; }
    public function correctedReadingId(): ?int { return $this->correctedReadingId; }
    public function isAnnulled(): bool { return $this->annulled; }
    public function annulledAt(): ?DateTimeImmutable { return $this->annulledAt; }
    public function annulledBy(): ?int { return $this->annulledBy; }
    public function annulmentReason(): ?string { return $this->annulmentReason; }

    private static function nullable(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
