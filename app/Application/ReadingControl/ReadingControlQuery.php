<?php

declare(strict_types=1);

namespace App\Application\ReadingControl;

use DateTimeImmutable;

final class ReadingControlQuery
{
    public function __construct(
        public readonly string $query = '',
        public readonly ?int $branchId = null,
        public readonly ?int $typeId = null,
        public readonly string $filter = 'all',
        public readonly int $page = 1,
        public readonly int $perPage = 25,
    ) {
    }

    public static function fromRequest(array $get): self
    {
        return new self(
            query: trim((string) ($get['q'] ?? '')),
            branchId: $get['sucursal_id'] !== null && $get['sucursal_id'] !== '' ? (int) $get['sucursal_id'] : null,
            typeId: $get['tipo_id'] !== null && $get['tipo_id'] !== '' ? (int) $get['tipo_id'] : null,
            filter: trim((string) ($get['filter'] ?? 'all')),
            page: max(1, (int) ($get['page'] ?? 1)),
            perPage: max(1, min(100, (int) ($get['per_page'] ?? 25))),
        );
    }
}

final readonly class EquipmentReadingControlRow
{
    public function __construct(
        public readonly int $equipmentId,
        public readonly string $equipmentCode,
        public readonly ?string $equipmentPlate,
        public readonly string $typeName,
        public readonly int $branchId,
        public readonly string $branchName,
        public readonly bool $controlsKm,
        public readonly int $driverEmployeeId,
        public readonly string $driverName,
        public readonly ?string $driverPhone,
        public readonly ?int $lastKm,
        public readonly ?string $lastReadingAt,
        public readonly int $daysSinceLastReading,
        public readonly ?int $lastClaimDeliveryId,
        public readonly ?string $lastClaimAt,
        public readonly ?string $lastClaimByUser,
        public readonly ?string $lastClaimStatus,
        public readonly ?string $lastClaimInstanceId,
    ) {
    }

    public function filterMatches(string $filter, DateTimeImmutable $now): bool
    {
        switch ($filter) {
            case 'today':
                if ($this->lastReadingAt === null) {
                    return false;
                }
                $last = new DateTimeImmutable($this->lastReadingAt);
                return $last->format('Y-m-d') === $now->format('Y-m-d');
            case 'not_today':
                if ($this->lastReadingAt === null) {
                    return true;
                }
                $last = new DateTimeImmutable($this->lastReadingAt);
                return $last->format('Y-m-d') !== $now->format('Y-m-d');
            case 'gt_3':
                return $this->daysSinceLastReading > 3;
            case 'gt_7':
                return $this->daysSinceLastReading > 7;
            case 'all':
            default:
                return true;
        }
    }

    public function hasDriver(): bool
    {
        return $this->driverEmployeeId > 0;
    }

    public function hasValidPhone(): bool
    {
        return $this->driverPhone !== null && trim($this->driverPhone) !== '';
    }

    public function hasReading(): bool
    {
        return $this->lastReadingAt !== null && $this->lastKm !== null;
    }
}