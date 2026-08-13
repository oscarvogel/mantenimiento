<?php

declare(strict_types=1);

namespace App\Domain\PreventiveMaintenance;

use InvalidArgumentException;

final readonly class PlantillaPreventiva
{
    public function __construct(
        public int $itemId,
        public int $templateId,
        public string $templateName,
        public int $serviceTypeId,
        public string $serviceName,
        public ?int $equipmentTypeId,
        public ?string $brand,
        public ?string $model,
        public ?int $intervalKm,
        public ?int $intervalHoursTenths,
        public ?int $intervalDays,
        public ?int $warningKm,
        public ?int $warningHoursTenths,
        public ?int $warningDays,
        public string $priority,
        public ?string $notes,
    ) {
        if ($itemId <= 0 || $templateId <= 0 || $serviceTypeId <= 0) {
            throw new InvalidArgumentException('La plantilla preventiva debe tener identificadores validos.');
        }
    }

    public function isCompatibleWith(int $equipmentTypeId, ?string $brand, ?string $model): bool
    {
        return ($this->equipmentTypeId === null || $this->equipmentTypeId === $equipmentTypeId)
            && ($this->brand === null || $this->sameText($this->brand, $brand))
            && ($this->model === null || $this->sameText($this->model, $model));
    }

    public function specificity(): int
    {
        if ($this->model !== null) {
            return 4;
        }

        if ($this->brand !== null && $this->equipmentTypeId !== null) {
            return 3;
        }

        if ($this->equipmentTypeId !== null) {
            return 2;
        }

        return 1;
    }

    private function sameText(string $expected, ?string $actual): bool
    {
        return $actual !== null
            && mb_strtoupper(trim($expected), 'UTF-8') === mb_strtoupper(trim($actual), 'UTF-8');
    }
}
