<?php

declare(strict_types=1);

namespace App\Domain\Assets;

use DateTimeImmutable;
use DomainException;

final class EquipmentRelation
{
    public const TRACTOR_TRAILER = 'TRACTOR_ACOPLADO';
    public const OTHER = 'OTRO';

    private function __construct(
        private readonly ?int $id,
        private readonly int $companyId,
        private readonly int $principalEquipmentId,
        private readonly int $relatedEquipmentId,
        private readonly string $type,
        private readonly DateTimeImmutable $startedAt,
        private ?DateTimeImmutable $endedAt,
        private readonly int $createdBy,
        private ?int $endedBy,
        private ?string $notes,
        private ?string $endingNotes,
    ) {
        if ($companyId <= 0 || $principalEquipmentId <= 0 || $relatedEquipmentId <= 0 || $createdBy <= 0) {
            throw new DomainException('Los identificadores de la relación deben ser válidos.');
        }
        if ($principalEquipmentId === $relatedEquipmentId) {
            throw new DomainException('Un equipo no puede relacionarse consigo mismo.');
        }
        if (! in_array($type, [self::TRACTOR_TRAILER, self::OTHER], true)) {
            throw new DomainException('El tipo de relación entre equipos no es válido.');
        }
        if ($endedAt !== null && $endedAt < $startedAt) {
            throw new DomainException('El fin de la relación no puede ser anterior a su inicio.');
        }
        $this->notes = self::normalizeNotes($notes);
        $this->endingNotes = self::normalizeNotes($endingNotes);
    }

    public static function start(
        int $companyId,
        int $principalEquipmentId,
        int $relatedEquipmentId,
        string $type,
        DateTimeImmutable $startedAt,
        int $createdBy,
        ?string $notes = null,
    ): self {
        return new self(null, $companyId, $principalEquipmentId, $relatedEquipmentId, mb_strtoupper(trim($type)), $startedAt, null, $createdBy, null, $notes, null);
    }

    public static function reconstitute(
        int $id,
        int $companyId,
        int $principalEquipmentId,
        int $relatedEquipmentId,
        string $type,
        DateTimeImmutable $startedAt,
        ?DateTimeImmutable $endedAt,
        int $createdBy,
        ?int $endedBy,
        ?string $notes,
        ?string $endingNotes,
    ): self {
        if ($id <= 0) {
            throw new DomainException('La identidad de la relación debe ser válida.');
        }

        return new self($id, $companyId, $principalEquipmentId, $relatedEquipmentId, $type, $startedAt, $endedAt, $createdBy, $endedBy, $notes, $endingNotes);
    }

    public function finish(DateTimeImmutable $endedAt, int $actorUserId, ?string $notes = null): void
    {
        if ($this->endedAt !== null) {
            throw new DomainException('La relación entre equipos ya se encuentra finalizada.');
        }
        if ($endedAt < $this->startedAt) {
            throw new DomainException('El fin de la relación no puede ser anterior a su inicio.');
        }
        if ($actorUserId <= 0) {
            throw new DomainException('El usuario que finaliza la relación debe ser válido.');
        }
        $this->endedAt = $endedAt;
        $this->endedBy = $actorUserId;
        $this->endingNotes = self::normalizeNotes($notes);
    }

    public function isIncompatibleWithActiveRelation(): bool
    {
        return $this->type === self::TRACTOR_TRAILER;
    }

    public function id(): ?int { return $this->id; }
    public function companyId(): int { return $this->companyId; }
    public function principalEquipmentId(): int { return $this->principalEquipmentId; }
    public function relatedEquipmentId(): int { return $this->relatedEquipmentId; }
    public function type(): string { return $this->type; }
    public function startedAt(): DateTimeImmutable { return $this->startedAt; }
    public function endedAt(): ?DateTimeImmutable { return $this->endedAt; }
    public function createdBy(): int { return $this->createdBy; }
    public function endedBy(): ?int { return $this->endedBy; }
    public function notes(): ?string { return $this->notes; }
    public function endingNotes(): ?string { return $this->endingNotes; }

    private static function normalizeNotes(?string $notes): ?string
    {
        $notes = trim((string) $notes);
        if (mb_strlen($notes) > 1000) {
            throw new DomainException('Las observaciones de la relación admiten hasta 1000 caracteres.');
        }

        return $notes === '' ? null : $notes;
    }
}
